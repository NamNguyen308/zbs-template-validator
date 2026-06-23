<?php
ob_start();
ini_set('display_errors', '0');
ini_set('log_errors', '1');
error_reporting(E_ALL);

try {
    header('Content-Type: application/json; charset=utf-8');

    $rawInput = file_get_contents("php://input");
    $payload = json_decode($rawInput, true);

    if (!$payload || !isset($payload['template'])) {
        respondJson([
            "status" => "error",
            "message" => "Missing template input."
        ]);
    }

    $templateRaw = trim((string)$payload['template']);
    $templateType = detectTemplateType($templateRaw);

    $textNodes = [];
    $buttonNodes = [];
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

    $preview = buildMessagePreview($textNodes);
    $status = count($violations) > 0 ? "fail" : "pass";

    respondJson([
        "status" => $status,
        "template_type" => $templateType,
        "rules_checked" => 6,
        "violations_count" => count($violations),
        "manual_notes_count" => 0,
        "preview" => $preview,
        "violations" => $violations,
        "suggestions" => array_values(array_unique(array_column($violations, 'suggestion')))
    ]);
} catch (Throwable $e) {
    respondJson([
        "status" => "error",
        "message" => "PHP validation error: " . $e->getMessage(),
        "file" => basename($e->getFile()),
        "line" => $e->getLine()
    ], 500);
}

function respondJson($data, $statusCode = 200) {
    if (ob_get_length()) {
        ob_clean();
    }
    http_response_code($statusCode);
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    exit;
}

/* =========================================================
   Extraction helpers
========================================================= */

function walkJson($node, $path, &$textNodes, &$buttonNodes) {
    if (!is_array($node)) return;

    foreach ($node as $key => $value) {
        $nextPath = is_numeric($key) ? "{$path}[{$key}]" : "{$path}.{$key}";

        if (is_string($value)) {
            $clean = cleanTextPreserveParams($value);
            $lowerPath = strtolower($nextPath);

            $isButtonText = str_contains($lowerPath, '.buttons.') || str_contains($lowerPath, '.c_buttons.');
            $isTextKey = in_array((string)$key, ['text', 'title', 'paragraph', 'c_title', 'c_paragraph', 'c_text'], true) || str_contains(strtolower((string)$key), 'text');

            if ($isTextKey && $clean !== '') {
                $textNodes[] = [
                    "type" => $isButtonText ? "button" : "message",
                    "role" => $isButtonText ? "button" : "message",
                    "location" => $nextPath,
                    "line" => null,
                    "section" => inferSectionFromPath($nextPath),
                    "field" => (string)$key,
                    "value" => $clean,
                    "source_line" => $value
                ];
            }

            if (in_array((string)$key, ['data', 'data_detail', 'url'], true)) {
                $buttonNodes[] = [
                    "location" => $nextPath,
                    "line" => null,
                    "value" => $value,
                    "source_line" => $value
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
    $mapItemIndex = null;
    $mapPart = null;

    foreach ($lines as $i => $line) {
        $lineNo = $i + 1;
        $trim = trim($line);

        if (preg_match('/^(\d+):\{/u', $trim, $m)) {
            if ($component === 'map_info' && $mapPart === null) {
                $mapItemIndex = $m[1];
            } else {
                $sectionIndex = $m[1];
                $subComponent = null;
                $mapItemIndex = null;
                $mapPart = null;
            }
        }

        if (preg_match('/"(oa_info|banner|map_info|buttons|open_utility|rating|carousel|custom_section)":\{/u', $trim, $m)) {
            $component = $m[1];
            $subComponent = null;
            if ($component !== 'map_info') {
                $mapItemIndex = null;
                $mapPart = null;
            }
        }

        if ($component === 'map_info' && preg_match('/"(key|value)":\{/u', $trim, $m)) {
            $mapPart = $m[1];
        }

        if ($component !== 'map_info' && preg_match('/"(title|key|value|click)":\{/u', $trim, $m)) {
            $subComponent = $m[1];
        }

        if (preg_match('/"text":string"(.*)"\s*$/u', $trim, $m)) {
            $rawText = $m[1];
            $clean = cleanTextPreserveParams($rawText);

            if ($clean !== '') {
                $role = 'message';
                $location = buildPseudoLocation($lineNo, $sectionIndex, $component, $subComponent, 'text');

                if ($component === 'buttons') {
                    $role = 'button';
                }

                if ($component === 'map_info') {
                    $role = $mapPart === 'key' ? 'map_key' : 'map_value';
                    $item = $mapItemIndex !== null ? $mapItemIndex : 'unknown';
                    $part = $mapPart ?: 'unknown';
                    $location = "line {$lineNo} | sections[{$sectionIndex}].map_info.items[{$item}].{$part}.text";
                }

                $nodes[] = [
                    "type" => $role === 'button' ? 'button' : 'message',
                    "role" => $role,
                    "location" => $location,
                    "line" => $lineNo,
                    "section" => $sectionIndex,
                    "field" => "text",
                    "value" => $clean,
                    "source_line" => $trim
                ];
            }
        }

        if (preg_match('/"c_title":string"(.*)"\s*$/u', $trim, $m)) {
            $clean = cleanTextPreserveParams($m[1]);
            $nodes[] = [
                "type" => "message",
                "role" => "carousel_title",
                "location" => "line {$lineNo} | carousel.card.title",
                "line" => $lineNo,
                "section" => $sectionIndex,
                "field" => "c_title",
                "value" => $clean,
                "source_line" => $trim
            ];
        }

        if (preg_match('/"c_paragraph":string"(.*)"\s*$/u', $trim, $m)) {
            $clean = cleanTextPreserveParams($m[1]);
            $nodes[] = [
                "type" => "message",
                "role" => "carousel_paragraph",
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

        if (preg_match('/"data":string"(.*)"\s*$/u', $trim, $m)) {
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
    if (preg_match('/sections\[(\d+)\]/', $path, $m)) return $m[1];
    return null;
}

function cleanTextPreserveParams($text) {
    $text = html_entity_decode((string)$text, ENT_QUOTES | ENT_HTML5, 'UTF-8');

    preg_match_all('/<[$A-Za-z0-9_]+>/u', $text, $matches);
    $placeholders = [];

    foreach ($matches[0] as $i => $param) {
        $token = "___PARAM_{$i}___";
        $placeholders[$token] = $param;
        $text = str_replace($param, $token, $text);
    }

    $text = preg_replace('/<span[^>]*>/iu', '', $text);
    $text = str_replace('</span>', '', $text);
    $text = strip_tags($text);

    foreach ($placeholders as $token => $param) {
        $text = str_replace($token, $param, $text);
    }

    $text = preg_replace('/\s+/u', ' ', $text);
    return trim($text);
}

function extractParamsFromTextNodes($textNodes) {
    $params = [];

    foreach ($textNodes as $node) {
        if (($node['type'] ?? '') === 'button') continue;

        preg_match_all('/<[^>]+>/u', $node['value'], $matches, PREG_OFFSET_CAPTURE);

        foreach ($matches[0] as $match) {
            $value = $match[0];
            $params[] = [
                "value" => $value,
                "name" => trim($value, '<>'),
                "location" => $node['location'],
                "line" => $node['line'],
                "text" => $node['value'],
                "offset" => $match[1],
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

function addViolation(&$violations, $ruleId, $ruleName, $category, $location, $value, $reason, $suggestion, $sourceLine = null) {
    $key = $ruleId . '|' . $location . '|' . $value;
    foreach ($violations as $existing) {
        if (($existing['_key'] ?? '') === $key) return;
    }

    $violations[] = [
        "_key" => $key,
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

function getMessageTextNodes($textNodes) {
    return array_values(array_filter($textNodes, fn($n) => ($n['type'] ?? '') !== 'button'));
}

function getButtonTextNodes($textNodes) {
    return array_values(array_filter($textNodes, fn($n) => ($n['type'] ?? '') === 'button'));
}

function hasCustomerSignal($textNodes, $params) {
    $combined = mb_strtolower(implode(' ', array_column(getMessageTextNodes($textNodes), 'value')), 'UTF-8');

    if (str_contains($combined, 'quý khách') || str_contains($combined, 'khách hàng') || str_contains($combined, 'mã khách hàng') || str_contains($combined, 'mã thành viên')) {
        return true;
    }

    $signals = ['customer', 'khach', 'cust', 'member', 'name', 'ten_khach_hang', 'customer_name', 'customer_code', 'id'];
    foreach ($params as $param) {
        $p = mb_strtolower($param['name'], 'UTF-8');
        foreach ($signals as $signal) {
            if ($p === $signal || str_contains($p, $signal)) return true;
        }
    }
    return false;
}

function hasStrongTransactionOrServiceContext($textNodes, $params) {
    $combined = mb_strtolower(implode(' ', array_column(getMessageTextNodes($textNodes), 'value')), 'UTF-8');

    $keywords = [
        'mã đơn hàng', 'mã sản phẩm', 'mã hợp đồng', 'mã lịch hẹn', 'mã hồ sơ', 'mã giao dịch', 'mã đặt chỗ', 'mã thanh toán',
        'thông báo tình trạng đơn hàng', 'đặt hẹn thành công', 'đã đặt hẹn', 'lịch hẹn', 'ngày hẹn', 'giờ hẹn', 'biển số xe',
        'nội dung hẹn', 'nơi hẹn', 'kỳ thanh toán', 'hạn thanh toán', 'tin đăng', 'ngày điều trị', 'cơ sở điều trị',
        'báo cáo định kỳ', 'hóa đơn', 'trạng thái đơn hàng'
    ];

    foreach ($keywords as $kw) {
        if (str_contains($combined, $kw)) return true;
    }

    $paramSignals = [
        'order', 'order_code', 'product', 'product_code', 'booking', 'booking_id', 'contract', 'contract_id', 'invoice', 'invoice_id',
        'service_name', 'service_date', 'appointment', 'transaction', 'transaction_id', 'car_id', 'date', 'time', 'month_year',
        'payment_status', 'listing', 'ma_ho_so', 'report', 'address'
    ];

    foreach ($params as $param) {
        $p = mb_strtolower($param['name'], 'UTF-8');
        foreach ($paramSignals as $signal) {
            if (str_contains($p, $signal)) return true;
        }
    }

    return false;
}

function isSurveyLike($textNodes) {
    $combined = mb_strtolower(implode(' ', array_column(getMessageTextNodes($textNodes), 'value')), 'UTF-8');
    foreach (['khảo sát', 'ý kiến', 'đánh giá', 'cảm nhận', 'chia sẻ cảm nhận', 'cải thiện dịch vụ'] as $kw) {
        if (str_contains($combined, $kw)) return true;
    }
    return false;
}

function findBestContextLocation($textNodes) {
    $messageNodes = getMessageTextNodes($textNodes);
    $surveyKeywords = ['khảo sát', 'ý kiến', 'đánh giá', 'cảm nhận', 'chia sẻ cảm nhận', 'cải thiện dịch vụ'];

    foreach ($messageNodes as $node) {
        $lower = mb_strtolower($node['value'], 'UTF-8');
        foreach ($surveyKeywords as $kw) {
            if (str_contains($lower, $kw)) return $node;
        }
    }

    return $messageNodes[0] ?? ["location" => "template.text", "value" => "No readable text", "source_line" => null];
}

function summarizeCustomerSignals($textNodes, $params) {
    $found = [];
    foreach ($params as $param) {
        $name = mb_strtolower($param['name'], 'UTF-8');
        if (str_contains($name, 'customer') || str_contains($name, 'cust') || str_contains($name, 'name') || $name === 'id') {
            $found[] = $param['value'] . ($param['line'] ? ' at line ' . $param['line'] : '');
        }
    }
    if (count($found)) return implode(', ', array_unique($found));

    $combined = mb_strtolower(implode(' ', array_column(getMessageTextNodes($textNodes), 'value')), 'UTF-8');
    if (str_contains($combined, 'quý khách')) return 'customer wording found: “Quý khách”';
    return 'No customer identifier found';
}

/* =========================================================
   Rules
========================================================= */

function runCustomerRelationshipCheck($textNodes, $params, &$violations) {
    if (hasCustomerSignal($textNodes, $params)) return;
    $target = getMessageTextNodes($textNodes)[0] ?? null;
    addViolation(
        $violations,
        'CUST_001',
        'Customer relationship is not clearly shown',
        'Customer Relationship',
        $target['location'] ?? 'template.text',
        'No customer/member identifier found',
        'The message does not clearly show that the recipient is an existing customer, member, or user of the business.',
        'Add a customer identifier, for example: Quý khách <customer_name> or Mã khách hàng <customer_code>.',
        $target['source_line'] ?? null
    );
}

function runTransactionServiceContextCheck($textNodes, $params, &$violations) {
    $hasCustomer = hasCustomerSignal($textNodes, $params);
    $hasContext = hasStrongTransactionOrServiceContext($textNodes, $params);
    if ($hasContext) return;
    if ($hasCustomer) return;

    $target = findBestContextLocation($textNodes);
    $reason = isSurveyLike($textNodes)
        ? 'The message asks for feedback or survey input, but it does not specify which order, service, appointment, or customer interaction triggered the survey.'
        : 'The message does not clearly explain which transaction, service, appointment, report, or activity triggered it.';
    $suggestion = isSurveyLike($textNodes)
        ? 'Add a concrete service context, for example: Quý khách đã sử dụng dịch vụ <service_name> vào ngày <service_date>, mã giao dịch <transaction_id>.'
        : 'Add context such as: Mã đơn hàng <order_code>, Mã lịch hẹn <booking_id>, Dịch vụ <service_name>, or Kỳ thanh toán <payment_period>.';
    addViolation($violations, 'CTX_001', 'Missing transaction or service context', 'Transaction / Service Context', $target['location'], $target['value'], $reason, $suggestion, $target['source_line']);
}

function runCustomerTransactionPairCheck($textNodes, $params, &$violations) {
    $hasCustomer = hasCustomerSignal($textNodes, $params);
    $hasContext = hasStrongTransactionOrServiceContext($textNodes, $params);
    if (!$hasCustomer || $hasContext) return;

    $target = findBestContextLocation($textNodes);
    $foundCustomer = summarizeCustomerSignals($textNodes, $params);
    $reason = isSurveyLike($textNodes)
        ? 'The template identifies the recipient, but the survey/feedback request is not tied to a specific order, service, appointment, transaction, or customer interaction.'
        : 'The template identifies the customer but does not include a specific order, service, appointment, contract, report, or activity identifier.';
    $suggestion = isSurveyLike($textNodes)
        ? 'Add service context before the survey CTA. Example: Quý khách đã sử dụng dịch vụ <service_name> vào ngày <service_date>, mã giao dịch <transaction_id>. Sau đó mời khách hàng thực hiện khảo sát.'
        : 'Add a paired identifier, for example: Mã đơn hàng <order_code>, Mã lịch hẹn <booking_id>, Mã hợp đồng <contract_id>, or Dịch vụ đã sử dụng <service_name>.';

    addViolation($violations, 'PAIR_001', 'Missing customer + transaction/service identifier pair', 'Customer + Transaction Pair', $target['location'], "Customer signal found: {$foundCustomer}. Missing transaction/service identifier.", $reason, $suggestion, $target['source_line']);
}

function runParameterFormatCheck($params, &$violations) {
    $seen = [];
    foreach ($params as $param) {
        $value = $param['value'];
        if (isset($seen[$value])) continue;
        $seen[$value] = true;
        if (!preg_match('/^<[A-Za-z0-9_\$]+>$/u', $value)) {
            addViolation($violations, 'PARAM_001', 'Invalid parameter format', 'Parameter Format', $param['location'], $value, 'Parameter names should not contain spaces, Vietnamese accents, hyphens, or unsupported special characters.', 'Rename it using letters, numbers, and underscores only. Example: <customer_name>.', $param['source_line']);
        }
    }
}

function findMapInfoLabelForParam($textNodes, $param) {
    if (!preg_match('/map_info\.items\[(\d+)\]\.value/u', $param['location'], $m)) return null;
    $itemIndex = $m[1];
    foreach ($textNodes as $node) {
        if (($node['role'] ?? '') === 'map_key' && preg_match('/map_info\.items\[' . preg_quote($itemIndex, '/') . '\]\.key/u', $node['location'])) {
            return $node['value'];
        }
    }
    return null;
}

function runParameterPrefixClarityCheck($textNodes, $params, &$violations) {
    foreach ($params as $param) {
        $mapInfoLabel = findMapInfoLabelForParam($textNodes, $param);
        if ($mapInfoLabel !== null && trim($mapInfoLabel) !== '') continue;

        $text = $param['text'];
        $value = $param['value'];
        $offset = $param['offset'];
        $name = mb_strtolower($param['name'], 'UTF-8');

        $before = mb_substr($text, max(0, $offset - 35), 35, 'UTF-8');
        $after = mb_substr($text, $offset + mb_strlen($value, 'UTF-8'), 25, 'UTF-8');

        $hasClearPrefix = preg_match('/(quý khách|khách hàng|mã khách hàng|mã đơn hàng|mã hợp đồng|mã lịch hẹn|mã|tên|điều kiện|hạn|hsd|số tiền|giá|ưu đãi|voucher|hạng|ngày|giờ|nơi|dịch vụ|trạng thái|biển số|tin đăng|môi giới|nội dung hẹn)\s*[:：]?\s*$/iu', $before);
        $isAdjacentToAnotherParam = preg_match('/^\s*<[^>]+>/u', $after);
        $isRiskyParam = str_contains($name, 'discount') || str_contains($name, 'summary') || str_contains($name, 'amount') || str_contains($name, 'price') || str_contains($name, 'cost') || str_contains($name, 'voucher') || str_contains($name, 'expired');

        if (in_array($name, ['date', 'time'], true)) continue;

        if (($isRiskyParam && !$hasClearPrefix) || $isAdjacentToAnotherParam) {
            addViolation($violations, 'PARAM_002', 'Parameter needs clearer prefix', 'Parameter Prefix Clarity', $param['location'], $value, 'This parameter appears without a clear label or is placed directly next to another parameter, making the value difficult to understand.', dynamicPrefixSuggestion($value), $param['source_line']);
        }
    }
}

function dynamicPrefixSuggestion($param) {
    $lower = mb_strtolower($param, 'UTF-8');
    if (str_contains($lower, 'discountdesc') || str_contains($lower, 'summary')) return "Rewrite as: Điều kiện áp dụng: {$param}.";
    if (str_contains($lower, 'amount') || str_contains($lower, 'price') || str_contains($lower, 'cost')) return "Rewrite as: Số tiền: {$param}.";
    if (str_contains($lower, 'voucher')) return "Rewrite as: Mã ưu đãi: {$param}.";
    if (str_contains($lower, 'date') || str_contains($lower, 'expired')) return "Rewrite as: HSD: {$param}.";
    return "Add a clear label before the parameter, for example: Mã khách hàng {$param} or Điều kiện áp dụng: {$param}.";
}

function runWritingQualityCheck($textNodes, &$violations) {
    $typoMap = [
        'KÍCH HỌA' => 'KÍCH HOẠT',
        'kích họa' => 'kích hoạt',
        'đề người dùng' => 'đến người dùng',
        'tiếp theo đúng khách hàng' => 'tiếp cận đúng khách hàng',
        'thương hiệu hiệu' => 'thương hiệu'
    ];
    foreach (getMessageTextNodes($textNodes) as $node) {
        if (($node['role'] ?? '') === 'map_value') continue;
        $text = $node['value'];
        foreach ($typoMap as $wrong => $correct) {
            if (mb_stripos($text, $wrong, 0, 'UTF-8') !== false) {
                addViolation($violations, 'TEXT_001', 'Possible typo or unnatural wording', 'Writing Quality', $node['location'], $wrong, 'The message contains wording that may be considered a typo or unnatural expression.', "Replace '{$wrong}' with '{$correct}'.", $node['source_line']);
            }
        }
        if (preg_match('/[!?.]{3,}/u', $text, $m)) {
            addViolation($violations, 'TEXT_002', 'Repeated punctuation', 'Writing Quality', $node['location'], $m[0], 'Repeated punctuation may make the message look unprofessional.', 'Use normal punctuation, for example one period or one exclamation mark.', $node['source_line']);
        }
    }
}

/* =========================================================
   Preview
========================================================= */

function buildMessagePreview($textNodes) {
    $messageNodes = array_values(array_filter($textNodes, function ($n) {
        return ($n['type'] ?? '') !== 'button' && !in_array(($n['role'] ?? ''), ['map_key', 'map_value'], true);
    }));
    $buttonNodes = getButtonTextNodes($textNodes);
    $mapRows = buildMapInfoRows($textNodes);
    $logo = inferLogoText($messageNodes);

    $title = null;
    $body = [];
    foreach ($messageNodes as $node) {
        if (!$title) $title = $node;
        else $body[] = $node;
    }

    return [
        "logo_text" => $logo,
        "title" => $title,
        "body" => array_slice($body, 0, 8),
        "info_rows" => $mapRows,
        "buttons" => array_slice($buttonNodes, 0, 3),
        "raw_nodes" => array_slice($textNodes, 0, 15)
    ];
}

function buildMapInfoRows($textNodes) {
    $rows = [];
    foreach ($textNodes as $node) {
        if (!preg_match('/map_info\.items\[(\d+)\]\.(key|value)/u', $node['location'], $m)) continue;
        $idx = $m[1];
        $part = $m[2];
        if (!isset($rows[$idx])) $rows[$idx] = ["label" => "", "value" => "", "location" => $node['location']];
        if ($part === 'key') $rows[$idx]['label'] = $node['value'];
        else {
            $rows[$idx]['value'] = $node['value'];
            $rows[$idx]['location'] = $node['location'];
        }
    }
    return array_values(array_filter($rows, fn($row) => trim($row['label']) !== '' || trim($row['value']) !== ''));
}

function inferLogoText($messageNodes) {
    foreach ($messageNodes as $node) {
        $text = trim($node['value']);
        if (preg_match('/^(.+?)\s+(xin chào|kính gửi|cảm ơn|thông báo)/iu', $text, $m)) return mb_strtoupper(trim($m[1]), 'UTF-8');
    }
    foreach ($messageNodes as $node) {
        $text = trim($node['value']);
        if (preg_match('/^(toyota\s+[^\s]+\s*[^\s]*)/iu', $text, $m)) return mb_strtoupper(trim($m[1]), 'UTF-8');
    }
    return 'BRAND';
}