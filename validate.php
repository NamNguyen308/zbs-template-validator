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

$templateRaw = trim($payload['template']);
$templateType = detectTemplateType($templateRaw);

$textNodes = [];
$buttonNodes = [];
$params = [];
$violations = [];

$decoded = json_decode($templateRaw, true);

if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {
    walkJson($decoded, '$', $textNodes, $buttonNodes);
} else {
    $textNodes = extractTextNodesFromPseudoJson($templateRaw);
    $buttonNodes = extractButtonsFromPseudoJson($templateRaw);
}

$params = extractParamsFromTextNodes($textNodes);

runCustomerRelationshipCheck($textNodes, $params, $violations);
runTransactionServiceContextCheck($textNodes, $params, $violations);
runCustomerTransactionPairCheck($textNodes, $params, $violations);
runParameterFormatCheck($params, $violations);
runParameterPrefixClarityCheck($textNodes, $params, $violations);
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


/* =========================================================
   Extraction helpers
========================================================= */

function walkJson($node, $path, &$textNodes, &$buttonNodes) {
    if (!is_array($node)) return;

    foreach ($node as $key => $value) {
        $nextPath = is_numeric($key) ? "{$path}[{$key}]" : "{$path}.{$key}";

        if (is_string($value)) {
            $textKeys = ['text', 'title', 'paragraph', 'c_title', 'c_paragraph'];

            if (in_array($key, $textKeys, true) || str_contains(strtolower($key), 'text')) {
                $clean = cleanText($value);

                if ($clean !== '') {
                    $textNodes[] = [
                        "location" => $nextPath,
                        "line" => null,
                        "section" => inferSectionFromPath($nextPath),
                        "field" => $key,
                        "value" => $clean,
                        "source_line" => $value
                    ];
                }
            }

            if (in_array($key, ['data', 'data_detail', 'url'], true)) {
                $buttonNodes[] = [
                    "location" => $nextPath,
                    "line" => null,
                    "value" => $value
                ];
            }
        }

        walkJson($value, $nextPath, $textNodes, $buttonNodes);
    }
}

function extractTextNodesFromPseudoJson($raw) {
    $lines = preg_split('/\r\n|\r|\n/', $raw);
    $nodes = [];

    $sectionIndex = null;
    $component = null;
    $subComponent = null;

    foreach ($lines as $i => $line) {
        $lineNo = $i + 1;
        $trim = trim($line);

        if (preg_match('/^(\d+):\{/', $trim, $m)) {
            $sectionIndex = $m[1];
        }

        if (preg_match('/"([^"]+)":\{/', $trim, $m)) {
            $component = $m[1];
        }

        if (preg_match('/"(title|key|value|click)":\{/', $trim, $m)) {
            $subComponent = $m[1];
        }

        if (preg_match('/"text":string"(.*)"/u', $trim, $m)) {
            $rawText = $m[1];
            $clean = cleanText($rawText);

            if ($clean !== '') {
                $location = buildPseudoLocation($lineNo, $sectionIndex, $component, $subComponent, 'text');

                $nodes[] = [
                    "location" => $location,
                    "line" => $lineNo,
                    "section" => $sectionIndex,
                    "field" => "text",
                    "value" => $clean,
                    "source_line" => $trim
                ];
            }
        }

        if (preg_match('/"c_title":string"(.*)"/u', $trim, $m)) {
            $clean = cleanText($m[1]);
            $nodes[] = [
                "location" => "line {$lineNo} | carousel.card.title",
                "line" => $lineNo,
                "section" => $sectionIndex,
                "field" => "c_title",
                "value" => $clean,
                "source_line" => $trim
            ];
        }

        if (preg_match('/"c_paragraph":string"(.*)"/u', $trim, $m)) {
            $clean = cleanText($m[1]);
            $nodes[] = [
                "location" => "line {$lineNo} | carousel.card.paragraph",
                "line" => $lineNo,
                "section" => $sectionIndex,
                "field" => "c_paragraph",
                "value" => $clean,
                "source_line" => $trim
            ];
        }
    }

    return $nodes;
}

function extractButtonsFromPseudoJson($raw) {
    $lines = preg_split('/\r\n|\r|\n/', $raw);
    $buttons = [];

    foreach ($lines as $i => $line) {
        $lineNo = $i + 1;
        $trim = trim($line);

        if (preg_match('/"data":string"(.*)"/u', $trim, $m)) {
            $buttons[] = [
                "location" => "line {$lineNo} | button.click.data",
                "line" => $lineNo,
                "value" => $m[1],
                "source_line" => $trim
            ];
        }
    }

    return $buttons;
}

function buildPseudoLocation($lineNo, $sectionIndex, $component, $subComponent, $field) {
    $section = $sectionIndex !== null ? "sections[{$sectionIndex}]" : "unknown_section";
    $comp = $component ?: "unknown_component";
    $sub = $subComponent ? ".{$subComponent}" : "";

    return "line {$lineNo} | {$section}.{$comp}{$sub}.{$field}";
}

function inferSectionFromPath($path) {
    if (preg_match('/sections\[(\d+)\]/', $path, $m)) {
        return $m[1];
    }

    return null;
}

function cleanText($text) {
    $text = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    $text = preg_replace('/<span[^>]*>/i', '', $text);
    $text = str_replace('</span>', '', $text);
    $text = strip_tags($text);
    $text = preg_replace('/\s+/u', ' ', $text);
    return trim($text);
}

function extractParamsFromTextNodes($textNodes) {
    $params = [];

    foreach ($textNodes as $node) {
        preg_match_all('/<[^>]+>/u', $node['value'], $matches, PREG_OFFSET_CAPTURE);

        foreach ($matches[0] as $match) {
            $value = $match[0];
            $offset = $match[1];

            $params[] = [
                "value" => $value,
                "name" => trim($value, '<>'),
                "location" => $node['location'],
                "line" => $node['line'],
                "text" => $node['value'],
                "offset" => $offset,
                "source_line" => $node['source_line']
            ];
        }
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


/* =========================================================
   Violation helper
========================================================= */

function addViolation(&$violations, $ruleId, $ruleName, $category, $location, $value, $reason, $suggestion, $sourceLine = null) {
    $violations[] = [
        "rule_id" => $ruleId,
        "rule_name" => $ruleName,
        "category" => $category,
        "location" => $location,
        "violating_value" => $value,
        "source_line" => $sourceLine,
        "reason" => $reason,
        "suggestion" => $suggestion,
        "review_type" => "auto_detected"
    ];
}


/* =========================================================
   Signal helpers
========================================================= */

function hasCustomerSignal($textNodes, $params) {
    $combined = mb_strtolower(implode(" ", array_column($textNodes, 'value')), 'UTF-8');

    if (
        str_contains($combined, 'quý khách') ||
        str_contains($combined, 'khách hàng') ||
        str_contains($combined, 'mã khách hàng') ||
        str_contains($combined, 'mã thành viên')
    ) {
        return true;
    }

    $customerParamSignals = [
        'customer', 'khach', 'khách', 'cust', 'member',
        'name', 'ten_khach_hang', 'customer_name', 'customer_code'
    ];

    foreach ($params as $param) {
        $p = mb_strtolower($param['name'], 'UTF-8');

        foreach ($customerParamSignals as $signal) {
            if (str_contains($p, $signal)) return true;
        }
    }

    return false;
}

function hasStrongTransactionOrServiceContext($textNodes, $params) {
    $combined = mb_strtolower(implode(" ", array_column($textNodes, 'value')), 'UTF-8');

    $strongKeywords = [
        'mã đơn hàng', 'mã hợp đồng', 'mã lịch hẹn', 'mã hồ sơ',
        'mã giao dịch', 'mã đặt chỗ', 'mã thanh toán',
        'đặt hẹn thành công', 'lịch hẹn', 'ngày hẹn', 'giờ hẹn',
        'biển số xe', 'nội dung hẹn', 'kỳ thanh toán',
        'hạn thanh toán', 'tin đăng', 'ngày điều trị',
        'cơ sở điều trị', 'báo cáo định kỳ', 'tháng'
    ];

    foreach ($strongKeywords as $kw) {
        if (str_contains($combined, $kw)) return true;
    }

    $contextParamSignals = [
        'order', 'order_code', 'booking', 'booking_id',
        'contract', 'contract_id', 'invoice', 'invoice_id',
        'service_name', 'appointment', 'transaction',
        'car_id', 'date', 'time', 'month_year',
        'payment_status', 'listing', 'ma_ho_so'
    ];

    foreach ($params as $param) {
        $p = mb_strtolower($param['name'], 'UTF-8');

        foreach ($contextParamSignals as $signal) {
            if (str_contains($p, $signal)) return true;
        }
    }

    return false;
}

function findBestContextLocation($textNodes) {
    $surveyKeywords = ['khảo sát', 'ý kiến', 'đánh giá', 'cảm nhận', 'chia sẻ cảm nhận', 'cải thiện dịch vụ'];

    foreach ($textNodes as $node) {
        $lower = mb_strtolower($node['value'], 'UTF-8');

        foreach ($surveyKeywords as $kw) {
            if (str_contains($lower, $kw)) {
                return $node;
            }
        }
    }

    return $textNodes[0] ?? [
        "location" => "template.text",
        "value" => "No readable text",
        "source_line" => null
    ];
}

function summarizeFoundCustomerParams($params) {
    $found = [];

    foreach ($params as $param) {
        $name = mb_strtolower($param['name'], 'UTF-8');

        if (
            str_contains($name, 'customer') ||
            str_contains($name, 'cust') ||
            str_contains($name, 'name') ||
            $name === 'id'
        ) {
            $found[] = $param['value'];
        }
    }

    return count($found) ? implode(', ', array_unique($found)) : 'No customer parameter found';
}


/* =========================================================
   Rule 1 — Customer relationship
========================================================= */

function runCustomerRelationshipCheck($textNodes, $params, &$violations) {
    if (hasCustomerSignal($textNodes, $params)) return;

    $target = $textNodes[0] ?? null;

    addViolation(
        $violations,
        "CUST_001",
        "Customer relationship is not clearly shown",
        "Customer Relationship",
        $target['location'] ?? 'template.text',
        "No customer/member identifier found",
        "The message does not clearly show that the recipient is an existing customer, member, or user of the business.",
        "Add a customer identifier, for example: Quý khách <customer_name> or Mã khách hàng <customer_code>.",
        $target['source_line'] ?? null
    );
}


/* =========================================================
   Rule 2 — Transaction / service context
========================================================= */

function runTransactionServiceContextCheck($textNodes, $params, &$violations) {
    if (hasStrongTransactionOrServiceContext($textNodes, $params)) return;

    $target = findBestContextLocation($textNodes);

    $isSurveyLike = false;
    $lower = mb_strtolower(implode(" ", array_column($textNodes, 'value')), 'UTF-8');

    foreach (['khảo sát', 'ý kiến', 'đánh giá', 'cảm nhận', 'cải thiện dịch vụ'] as $kw) {
        if (str_contains($lower, $kw)) {
            $isSurveyLike = true;
            break;
        }
    }

    $reason = $isSurveyLike
        ? "The message asks for feedback or survey input, but it does not specify which order, service, appointment, or customer interaction triggered the survey."
        : "The message does not clearly explain which transaction, service, appointment, report, or activity triggered it.";

    $suggestion = $isSurveyLike
        ? "Add a concrete service context, for example: Quý khách đã sử dụng dịch vụ <service_name> vào ngày <service_date>, mã giao dịch <transaction_id>."
        : "Add context such as: Mã đơn hàng <order_code>, Mã lịch hẹn <booking_id>, Dịch vụ <service_name>, or Kỳ thanh toán <payment_period>.";

    addViolation(
        $violations,
        "CTX_001",
        "Missing transaction or service context",
        "Transaction / Service Context",
        $target['location'],
        $target['value'],
        $reason,
        $suggestion,
        $target['source_line']
    );
}


/* =========================================================
   Rule 3 — Customer + transaction pair
========================================================= */

function runCustomerTransactionPairCheck($textNodes, $params, &$violations) {
    $hasCustomer = hasCustomerSignal($textNodes, $params);
    $hasContext = hasStrongTransactionOrServiceContext($textNodes, $params);

    if (!$hasCustomer || $hasContext) return;

    $target = findBestContextLocation($textNodes);
    $foundCustomer = summarizeFoundCustomerParams($params);

    addViolation(
        $violations,
        "PAIR_001",
        "Missing customer + transaction/service identifier pair",
        "Customer + Transaction Pair",
        $target['location'],
        "Found customer identifier(s): {$foundCustomer}; no transaction/service identifier found",
        "The template identifies the customer but does not include a specific order, service, appointment, contract, report, or activity identifier.",
        "Add a paired identifier, for example: Mã đơn hàng <order_code>, Mã lịch hẹn <booking_id>, Mã hợp đồng <contract_id>, or Dịch vụ đã sử dụng <service_name>.",
        $target['source_line']
    );
}


/* =========================================================
   Rule 4 — Parameter format
========================================================= */

function runParameterFormatCheck($params, &$violations) {
    $seen = [];

    foreach ($params as $param) {
        $value = $param['value'];

        if (isset($seen[$value])) continue;
        $seen[$value] = true;

        $isValid = preg_match('/^<[A-Za-z0-9_\$]+>$/u', $value);

        if (!$isValid) {
            addViolation(
                $violations,
                "PARAM_001",
                "Invalid parameter format",
                "Parameter Format",
                $param['location'],
                $value,
                "Parameter names should not contain spaces, Vietnamese accents, hyphens, or unsupported special characters.",
                "Rename it using letters, numbers, and underscores only. Example: <customer_name>.",
                $param['source_line']
            );
        }
    }
}


/* =========================================================
   Rule 5 — Parameter prefix clarity
========================================================= */

function runParameterPrefixClarityCheck($textNodes, $params, &$violations) {
    foreach ($params as $param) {
        $text = $param['text'];
        $value = $param['value'];
        $offset = $param['offset'];
        $name = mb_strtolower($param['name'], 'UTF-8');

        $before = mb_substr($text, max(0, $offset - 35), 35, 'UTF-8');
        $after = mb_substr($text, $offset + mb_strlen($value, 'UTF-8'), 25, 'UTF-8');

        $hasClearPrefix = preg_match('/(quý khách|khách hàng|mã khách hàng|mã đơn hàng|mã hợp đồng|mã lịch hẹn|mã|tên|điều kiện|hạn|hsd|số tiền|giá|ưu đãi|voucher|hạng|ngày|giờ|nơi|dịch vụ|trạng thái|biển số|tin đăng|môi giới)\s*[:：]?\s*$/iu', $before);

        $isAdjacentToAnotherParam = preg_match('/^\s*<[^>]+>/u', $after);

        $isRiskyParam =
            str_contains($name, 'discount') ||
            str_contains($name, 'summary') ||
            str_contains($name, 'amount') ||
            str_contains($name, 'price') ||
            str_contains($name, 'cost') ||
            str_contains($name, 'voucher') ||
            str_contains($name, 'expired') ||
            str_contains($name, 'date');

        if (($isRiskyParam && !$hasClearPrefix) || $isAdjacentToAnotherParam) {
            addViolation(
                $violations,
                "PARAM_002",
                "Parameter needs clearer prefix",
                "Parameter Prefix Clarity",
                $param['location'],
                $value,
                "This parameter appears without a clear label or is placed directly next to another parameter, making the value difficult to understand.",
                dynamicPrefixSuggestion($value),
                $param['source_line']
            );
        }
    }
}

function dynamicPrefixSuggestion($param) {
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


/* =========================================================
   Rule 6 — Writing quality
========================================================= */

function runWritingQualityCheck($textNodes, &$violations) {
    $typoMap = [
        'KÍCH HỌA' => 'KÍCH HOẠT',
        'kích họa' => 'kích hoạt',
        'đề người dùng' => 'đến người dùng',
        'tiếp theo đúng khách hàng' => 'tiếp cận đúng khách hàng',
        'thương hiệu hiệu' => 'thương hiệu'
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
                    "The message contains wording that may be considered a typo or unnatural expression.",
                    "Replace '{$wrong}' with '{$correct}'.",
                    $node['source_line']
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
                "Use normal punctuation, for example one period or one exclamation mark.",
                $node['source_line']
            );
        }
    }
}


/* =========================================================
   Preview
========================================================= */

function buildPreview($textNodes) {
    $preview = [];

    foreach ($textNodes as $node) {
        if ($node['value'] === '') continue;

        $preview[] = [
            "location" => $node['location'],
            "line" => $node['line'],
            "text" => $node['value']
        ];
    }

    return array_slice($preview, 0, 15);
}