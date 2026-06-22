<?php
header('Content-Type: application/json; charset=utf-8');

$rawInput = file_get_contents("php://input");
$payload = json_decode($rawInput, true);

if (!$payload || !isset($payload['template'])) {
    echo json_encode([
        "status" => "error",
        "message" => "Missing template input."
    ], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    exit;
}

$templateRaw = $payload['template'];
$decoded = json_decode($templateRaw, true);

$violations = [];
$textNodes = [];
$params = [];
$buttons = [];
$templateType = detectTemplateType($templateRaw);

if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {
    walkJson($decoded, '$', $textNodes, $buttons);
} else {
    // Fallback for pasted pseudo-JSON / exported text
    $textNodes = extractTextFromRaw($templateRaw);
    $buttons = extractButtonsFromRaw($templateRaw);
}

$allText = implode("\n", array_column($textNodes, 'value'));
$params = extractParams($allText . "\n" . $templateRaw);

runCustomerRelationshipCheck($textNodes, $params, $violations);
runTransactionContextCheck($textNodes, $params, $violations);
runCustomerTransactionPairCheck($textNodes, $params, $violations);
runParameterFormatCheck($params, $violations);
runParameterPrefixCheck($textNodes, $violations);
runWritingQualityCheck($textNodes, $violations);

$preview = buildPreview($textNodes);

$status = count($violations) > 0 ? "fail" : "pass";

echo json_encode([
    "status" => $status,
    "template_type" => $templateType,
    "rules_checked" => 6,
    "violations_count" => count($violations),
    "manual_notes_count" => 0,
    "preview" => $preview,
    "violations" => $violations,
    "suggestions" => array_values(array_unique(array_column($violations, 'suggestion')))
], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);


/* -------------------- Helpers -------------------- */

function walkJson($node, $path, &$textNodes, &$buttons) {
    if (is_array($node)) {
        foreach ($node as $key => $value) {
            $nextPath = is_numeric($key) ? "{$path}[{$key}]" : "{$path}.{$key}";

            if (is_string($value)) {
                if ($key === 'text' || $key === 'title' || $key === 'paragraph' || str_contains($key, 'text')) {
                    $clean = cleanText($value);
                    if ($clean !== '') {
                        $textNodes[] = [
                            "location" => $nextPath,
                            "value" => $clean
                        ];
                    }
                }

                if ($key === 'data' || $key === 'data_detail' || $key === 'url') {
                    $buttons[] = [
                        "location" => $nextPath,
                        "value" => $value
                    ];
                }
            }

            walkJson($value, $nextPath, $textNodes, $buttons);
        }
    }
}

function extractTextFromRaw($raw) {
    $nodes = [];
    preg_match_all('/""text"":string""(.*?)""/us', $raw, $matches, PREG_OFFSET_CAPTURE);

    foreach ($matches[1] as $index => $match) {
        $nodes[] = [
            "location" => "raw.text[$index]",
            "value" => cleanText($match[0])
        ];
    }

    // Carousel fallback
    preg_match_all('/""c_title"":string""(.*?)""|""c_paragraph"":string""(.*?)""|""title"":string""(.*?)""|""paragraph"":string""(.*?)""/us', $raw, $carouselMatches, PREG_SET_ORDER);

    foreach ($carouselMatches as $index => $m) {
        $value = '';
        for ($i = 1; $i < count($m); $i++) {
            if (!empty($m[$i])) {
                $value = $m[$i];
                break;
            }
        }

        if ($value !== '') {
            $nodes[] = [
                "location" => "raw.carousel_text[$index]",
                "value" => cleanText($value)
            ];
        }
    }

    return $nodes;
}

function extractButtonsFromRaw($raw) {
    $buttons = [];
    preg_match_all('/""data"":string""(.*?)""/us', $raw, $matches);

    foreach ($matches[1] as $index => $value) {
        $buttons[] = [
            "location" => "raw.button_data[$index]",
            "value" => $value
        ];
    }

    return $buttons;
}

function cleanText($text) {
    $text = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    $text = preg_replace('/<span[^>]*>/i', '', $text);
    $text = str_replace('</span>', '', $text);
    $text = strip_tags($text);
    $text = preg_replace('/\s+/u', ' ', $text);
    return trim($text);
}

function extractParams($text) {
    preg_match_all('/<[^>]+>/u', $text, $matches, PREG_OFFSET_CAPTURE);

    $params = [];
    foreach ($matches[0] as $match) {
        $params[] = [
            "value" => $match[0],
            "offset" => $match[1]
        ];
    }

    return $params;
}

function detectTemplateType($raw) {
    $lower = mb_strtolower($raw, 'UTF-8');

    if (str_contains($lower, 'open_utility') && str_contains($lower, 'payment')) return 'Payment';
    if (str_contains($lower, 'open_utility') && str_contains($lower, 'voucher')) return 'Voucher';
    if (str_contains($lower, 'rating') && str_contains($lower, 'star=')) return 'Rating';
    if (str_contains($lower, '<otp>') || str_contains($lower, 'copy.clipboard')) return 'Authentication / OTP';
    if (str_contains($lower, 'carousel') || str_contains($lower, 'c_card')) return 'Carousel';

    return 'Custom / Unknown';
}

function addViolation(&$violations, $ruleId, $ruleName, $category, $location, $value, $reason, $suggestion) {
    $violations[] = [
        "rule_id" => $ruleId,
        "rule_name" => $ruleName,
        "category" => $category,
        "location" => $location,
        "violating_value" => $value,
        "reason" => $reason,
        "suggestion" => $suggestion,
        "review_type" => "auto_detected"
    ];
}

/* -------------------- Rule 1 -------------------- */

function runCustomerRelationshipCheck($textNodes, $params, &$violations) {
    $combined = mb_strtolower(implode(" ", array_column($textNodes, 'value')), 'UTF-8');

    $customerSignals = [
        'customer_name', 'ten_khach_hang', 'name', 'customer_id',
        'customer_code', 'ma_khach_hang', 'member_code',
        'ma_thanh_vien', 'custid', 'phone_number'
    ];

    $hasCustomerSignal = false;
    foreach ($params as $param) {
        $p = mb_strtolower($param['value'], 'UTF-8');
        foreach ($customerSignals as $signal) {
            if (str_contains($p, $signal)) {
                $hasCustomerSignal = true;
                break 2;
            }
        }
    }

    if (!$hasCustomerSignal && !str_contains($combined, 'quý khách') && !str_contains($combined, 'khách hàng')) {
        addViolation(
            $violations,
            "CUST_001",
            "Missing customer relationship indicator",
            "Customer Relationship",
            "template.text",
            "No customer identifier found",
            "The template does not clearly identify the recipient as a customer, member, or user of the business.",
            "Add customer information such as: Quý khách <customer_name> or Mã khách hàng <customer_code>."
        );
    }
}

/* -------------------- Rule 2 -------------------- */

function runTransactionContextCheck($textNodes, $params, &$violations) {
    $combined = mb_strtolower(implode(" ", array_column($textNodes, 'value')), 'UTF-8');

    $contextKeywords = [
        'mã đơn hàng', 'đơn hàng', 'mã hợp đồng', 'hợp đồng',
        'mã lịch hẹn', 'lịch hẹn', 'đặt hẹn', 'cuộc hẹn',
        'dịch vụ', 'ngày giao dịch', 'kỳ thanh toán',
        'hạn thanh toán', 'mã hồ sơ', 'tin đăng',
        'báo cáo', 'tháng', 'trạng thái', 'biển số xe',
        'ngày hẹn', 'giờ hẹn', 'nơi hẹn'
    ];

    $contextParams = [
        'order', 'order_code', 'ma_don_hang', 'booking',
        'contract', 'invoice', 'service', 'service_name',
        'transaction', 'appointment', 'booking_id',
        'car_id', 'date', 'time', 'month_year',
        'report', 'payment_status', 'listing'
    ];

    $hasContext = false;

    foreach ($contextKeywords as $kw) {
        if (str_contains($combined, $kw)) {
            $hasContext = true;
            break;
        }
    }

    if (!$hasContext) {
        foreach ($params as $param) {
            $p = mb_strtolower($param['value'], 'UTF-8');
            foreach ($contextParams as $signal) {
                if (str_contains($p, $signal)) {
                    $hasContext = true;
                    break 2;
                }
            }
        }
    }

    if (!$hasContext) {
        addViolation(
            $violations,
            "CTX_001",
            "Missing transaction or service context",
            "Transaction / Service Context",
            "template.text",
            "No transaction/service context found",
            "The message does not clearly explain which transaction, service, appointment, report, or activity triggered it.",
            "Add context such as: Mã đơn hàng <order_code>, Mã lịch hẹn <booking_id>, Dịch vụ <service_name>, or Kỳ thanh toán <payment_period>."
        );
    }
}

/* -------------------- Rule 3 -------------------- */

function runCustomerTransactionPairCheck($textNodes, $params, &$violations) {
    $customerParamFound = false;
    $contextParamFound = false;

    $customerSignals = ['customer', 'khach', 'khách', 'member', 'cust', 'name', 'ten_'];
    $contextSignals = ['order', 'don_hang', 'đơn', 'booking', 'contract', 'invoice', 'service', 'appointment', 'car_id', 'date', 'time', 'month', 'payment', 'listing', 'report', 'ma_ho_so'];

    foreach ($params as $param) {
        $p = mb_strtolower($param['value'], 'UTF-8');

        foreach ($customerSignals as $signal) {
            if (str_contains($p, $signal)) {
                $customerParamFound = true;
                break;
            }
        }

        foreach ($contextSignals as $signal) {
            if (str_contains($p, $signal)) {
                $contextParamFound = true;
                break;
            }
        }
    }

    if ($customerParamFound && !$contextParamFound) {
        addViolation(
            $violations,
            "PAIR_001",
            "Missing customer + transaction/service identifier pair",
            "Customer + Transaction Pair",
            "template.parameters",
            "Customer parameter found, but no transaction/service identifier found",
            "The template identifies the customer but does not include a transaction, service, account, or activity identifier.",
            "Add a paired identifier such as: Mã khách hàng <customer_code>, Mã đơn hàng <order_code>, Mã hợp đồng <contract_id>, or Dịch vụ <service_name>."
        );
    }
}

/* -------------------- Rule 4 -------------------- */

function runParameterFormatCheck($params, &$violations) {
    $seen = [];

    foreach ($params as $param) {
        $value = $param['value'];

        if (isset($seen[$value])) continue;
        $seen[$value] = true;

        if (!preg_match('/^<[A-Za-z0-9_\\$]+>$/u', $value)) {
            addViolation(
                $violations,
                "PARAM_001",
                "Invalid parameter format",
                "Parameter Format",
                "template.parameters",
                $value,
                "Parameter should not contain spaces, Vietnamese accents, hyphens, or special characters.",
                "Rename the parameter using only letters, numbers, underscores, and optional system prefix. Example: <customer_name>."
            );
        }
    }
}

/* -------------------- Rule 5 -------------------- */

function runParameterPrefixCheck($textNodes, &$violations) {
    $riskyParams = [
        'discount_discountdesc',
        'discount_summary',
        'discount_discountamount',
        'voucher_code',
        'expireddate',
        'discount_enddate',
        'cost',
        'price',
        'amount'
    ];

    foreach ($textNodes as $node) {
        $text = $node['value'];

        preg_match_all('/<[^>]+>/u', $text, $matches, PREG_OFFSET_CAPTURE);

        foreach ($matches[0] as $match) {
            $param = $match[0];
            $offset = $match[1];
            $paramName = mb_strtolower(trim($param, '<>'), 'UTF-8');

            $before = mb_substr($text, max(0, $offset - 30), 30, 'UTF-8');
            $after = mb_substr($text, $offset + mb_strlen($param, 'UTF-8'), 20, 'UTF-8');

            $hasPrefix = preg_match('/(quý khách|khách hàng|mã|tên|điều kiện|hạn|hsd|số tiền|giá|ưu đãi|voucher|hạng|ngày|giờ|nơi|dịch vụ|trạng thái|biển số|tin đăng|môi giới|kỳ thanh toán)\s*[:：]?\s*$/iu', $before);

            $isAdjacentToAnotherParam = preg_match('/^\\s*<[^>]+>/u', $after);

            $isRisky = false;
            foreach ($riskyParams as $risky) {
                if (str_contains($paramName, $risky)) {
                    $isRisky = true;
                    break;
                }
            }

            if (($isRisky && !$hasPrefix) || $isAdjacentToAnotherParam) {
                addViolation(
                    $violations,
                    "PARAM_002",
                    "Parameter needs clearer prefix",
                    "Parameter Prefix Clarity",
                    $node['location'],
                    $param,
                    "This parameter appears without a clear label or is placed directly next to another parameter, making its meaning unclear.",
                    suggestPrefix($param)
                );
            }
        }
    }
}

function suggestPrefix($param) {
    $lower = mb_strtolower($param, 'UTF-8');

    if (str_contains($lower, 'discountdesc') || str_contains($lower, 'summary')) {
        return "Rewrite as: Điều kiện áp dụng: {$param}.";
    }

    if (str_contains($lower, 'amount') || str_contains($lower, 'price') || str_contains($lower, 'cost')) {
        return "Rewrite as: Số tiền: {$param}.";
    }

    if (str_contains($lower, 'voucher')) {
        return "Rewrite as: Mã ưu đãi: {$param}.";
    }

    if (str_contains($lower, 'date') || str_contains($lower, 'expired')) {
        return "Rewrite as: HSD: {$param}.";
    }

    return "Add a clear label before the parameter, for example: Mã khách hàng {$param} or Điều kiện áp dụng: {$param}.";
}

/* -------------------- Rule 6 -------------------- */

function runWritingQualityCheck($textNodes, &$violations) {
    $typoMap = [
        'KÍCH HỌA' => 'KÍCH HOẠT',
        'kích họa' => 'kích hoạt',
        'KHÁCH HÀNG hạng' => 'khách hàng hạng',
        'đề người dùng' => 'đến người dùng',
        'tiếp theo đúng khách hàng' => 'tiếp cận đúng khách hàng'
    ];

    foreach ($textNodes as $node) {
        $text = $node['value'];

        foreach ($typoMap as $wrong => $correct) {
            if (mb_stripos($text, $wrong, 0, 'UTF-8') !== false) {
                addViolation(
                    $violations,
                    "TEXT_001",
                    "Possible typo or unnatural wording",
                    "Writing Quality",
                    $node['location'],
                    $wrong,
                    "The template contains wording that may be considered a typo or unnatural expression.",
                    "Replace '{$wrong}' with '{$correct}'."
                );
            }
        }

        if (preg_match('/[!?.]{3,}/u', $text, $m)) {
            addViolation(
                $violations,
                "TEXT_002",
                "Repeated punctuation",
                "Writing Quality",
                $node['location'],
                $m[0],
                "Repeated punctuation may make the message look unprofessional.",
                "Use normal punctuation, for example one period or one exclamation mark."
            );
        }
    }
}

/* -------------------- Preview -------------------- */

function buildPreview($textNodes) {
    $preview = [];

    foreach ($textNodes as $node) {
        $value = $node['value'];

        if ($value === '') continue;

        $preview[] = [
            "location" => $node['location'],
            "text" => $value
        ];
    }

    return array_slice($preview, 0, 12);
}