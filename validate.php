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

    $preview = buildMessagePreview($textNodes, $templateRaw);
    $status = count($violations) > 0 ? "fail" : "pass";

    respondJson([
        "status" => $status,
        "template_type" => $templateType,
        "rules_checked" => 5,
        "violations_count" => count($violations),
        "manual_notes_count" => 0,
        "preview" => $preview,
        "violations" => array_map(function ($v) {
            unset($v['_key']);
            return $v;
        }, $violations),
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
                $role = $isButtonText ? 'button' : 'message';

                if (str_contains($lowerPath, 'open_utility')) {
                    $role = 'utility';
                }

                $textNodes[] = [
                    "type" => $role === "button" ? "button" : "message",
                    "role" => $role,
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

        if (preg_match('/^(\d+):\{1 item/u', $trim, $m)) {
            $sectionIndex = $m[1];
            $subComponent = null;
            $mapItemIndex = null;
            $mapPart = null;
        }

        if (preg_match('/"{1,2}(oa_info|banner|map_info|buttons|open_utility|rating|carousel|custom_section|map_image)"{1,2}:\{/u', $trim, $m)) {
            $component = $m[1];
            $subComponent = null;

            if ($component !== 'map_info') {
                $mapItemIndex = null;
                $mapPart = null;
            }
        }

        if ($component === 'map_info' && preg_match('/^(\d+):\{5 items/u', $trim, $m)) {
            $mapItemIndex = $m[1];
            $mapPart = null;
        }

        if ($component === 'map_info' && preg_match('/"{1,2}(key|value)"{1,2}:\{/u', $trim, $m)) {
            $mapPart = $m[1];
        }

        if ($component !== 'map_info' && preg_match('/"{1,2}(title|key|value|click|contents)"{1,2}:\{/u', $trim, $m)) {
            $subComponent = $m[1];
        }

        if (preg_match('/"{1,2}text"{1,2}:string"{1,2}(.*)"{1,2}\s*$/u', $trim, $m)) {
            $rawText = $m[1];
            $clean = cleanTextPreserveParams($rawText);

            if ($clean !== '') {
                $role = 'message';
                $type = 'message';
                $location = buildPseudoLocation($lineNo, $sectionIndex, $component, $subComponent, 'text');

                if ($component === 'buttons') {
                    $role = 'button';
                    $type = 'button';
                }

                if ($component === 'open_utility') {
                    $role = 'utility';
                    $type = 'message';
                    $location = "line {$lineNo} | sections[{$sectionIndex}].open_utility.text";
                }

                if ($component === 'map_info') {
                    $role = $mapPart === 'key' ? 'map_key' : 'map_value';
                    $type = 'message';
                    $item = $mapItemIndex !== null ? $mapItemIndex : 'unknown';
                    $part = $mapPart ?: 'unknown';
                    $location = "line {$lineNo} | sections[{$sectionIndex}].map_info.items[{$item}].{$part}.text";
                }

                $nodes[] = [
                    "type" => $type,
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

        if (preg_match('/"{1,2}c_title"{1,2}:string"{1,2}(.*)"{1,2}\s*$/u', $trim, $m)) {
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

        if (preg_match('/"{1,2}c_paragraph"{1,2}:string"{1,2}(.*)"{1,2}\s*$/u', $trim, $m)) {
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

        if (preg_match('/"{1,2}data"{1,2}:string"{1,2}(.*)"{1,2}\s*$/u', $trim, $m)) {
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
    $text = str_replace('""', '"', $text);
    $text = preg_replace('/<span[^>]*>/iu', '', $text);
    $text = str_replace('</span>', '', $text);
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
                "section" => $node['section'] ?? null,
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

/* =========================================================
   Violation helper
========================================================= */

function addViolation(&$violations, $ruleId, $ruleName, $category, $location, $value, $reason, $suggestion, $sourceLine = null) {
    $newKey = normalizeViolationKey($ruleId, $location, $value);

    foreach ($violations as $existing) {
        $existingKey = normalizeViolationKey(
            $existing['rule_id'] ?? '',
            $existing['location'] ?? '',
            $existing['violating_value'] ?? ''
        );

        if ($existingKey === $newKey) {
            return;
        }
    }

    $violations[] = [
        "_key" => $newKey,
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

function normalizeViolationKey($ruleId, $location, $value) {
    $value = mb_strtolower(trim((string)$value), 'UTF-8');
    $value = preg_replace('/\s+/u', ' ', $value);
    return $ruleId . '|' . $location . '|' . $value;
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

    if (
        str_contains($combined, 'quý khách') ||
        str_contains($combined, 'khách hàng') ||
        str_contains($combined, 'mã khách hàng') ||
        str_contains($combined, 'mã thành viên')
    ) {
        return true;
    }

    $signals = ['customer_name', 'ten_khach_hang', 'customer', 'cust', 'member', 'name'];

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
        'mã đơn hàng',
        'mã sản phẩm',
        'mã hợp đồng',
        'mã lịch hẹn',
        'mã hồ sơ',
        'mã giao dịch',
        'mã đặt chỗ',
        'mã thanh toán',
        'thông báo tình trạng đơn hàng',
        'đặt hẹn thành công',
        'đã đặt hẹn',
        'lịch hẹn',
        'ngày hẹn',
        'giờ hẹn',
        'biển số xe',
        'nội dung hẹn',
        'nơi hẹn',
        'kỳ thanh toán',
        'hạn thanh toán',
        'hóa đơn',
        'trạng thái đơn hàng',
        'tin đăng',
        'ngày điều trị',
        'cơ sở điều trị',
        'báo cáo định kỳ'
    ];

    foreach ($keywords as $kw) {
        if (str_contains($combined, $kw)) return true;
    }

    $paramSignals = [
        'order_code',
        'order_id',
        'ma_don_hang',
        'product_code',
        'booking_id',
        'contract_id',
        'invoice_id',
        'service_name',
        'service_date',
        'appointment_id',
        'transaction_id',
        'car_id',
        'payment_period',
        'payment_status',
        'listing_id',
        'report_id',
        'ma_ho_so',
        'so_can_ho',
        'dot_thanh_toan',
        'ngay_thanh_toan'
    ];

    foreach ($params as $param) {
        $p = mb_strtolower($param['name'], 'UTF-8');

        foreach ($paramSignals as $signal) {
            if (str_contains($p, $signal)) return true;
        }
    }

    return false;
}

function hasCustomerIdentifierPair($textNodes, $params) {
    $combined = mb_strtolower(implode(' ', array_column(getMessageTextNodes($textNodes), 'value')), 'UTF-8');

    $pairKeywords = [
        'mã khách hàng',
        'mã thành viên',
        'mã đơn hàng',
        'mã hợp đồng',
        'mã giao dịch',
        'mã sản phẩm',
        'mã lịch hẹn',
        'mã hồ sơ'
    ];

    foreach ($pairKeywords as $kw) {
        if (str_contains($combined, $kw)) return true;
    }

    $pairParamSignals = [
        'customer_code',
        'customer_id',
        'member_code',
        'ma_khach_hang',
        'order_code',
        'order_id',
        'contract_id',
        'transaction_id',
        'booking_id',
        'invoice_id',
        'service_name',
        'so_can_ho',
        'dot_thanh_toan'
    ];

    foreach ($params as $param) {
        $p = mb_strtolower($param['name'], 'UTF-8');

        foreach ($pairParamSignals as $signal) {
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

function isVoucherLike($textNodes) {
    $combined = mb_strtolower(implode(' ', array_column(getMessageTextNodes($textNodes), 'value')), 'UTF-8');

    foreach (['voucher', 'mã ưu đãi', 'ưu đãi', 'giảm', 'hạng thành viên', 'sinh nhật'] as $kw) {
        if (str_contains($combined, $kw)) return true;
    }

    return false;
}

function findBestContextLocation($textNodes) {
    $messageNodes = getMessageTextNodes($textNodes);
    $keywords = ['khảo sát', 'ý kiến', 'đánh giá', 'cảm nhận', 'voucher', 'ưu đãi', 'giảm', 'sinh nhật'];

    foreach ($messageNodes as $node) {
        $lower = mb_strtolower($node['value'], 'UTF-8');

        foreach ($keywords as $kw) {
            if (str_contains($lower, $kw)) return $node;
        }
    }

    return $messageNodes[0] ?? [
        "location" => "template.text",
        "value" => "No readable text",
        "source_line" => null
    ];
}

function summarizeCustomerSignals($textNodes, $params) {
    $found = [];

    foreach ($params as $param) {
        $name = mb_strtolower($param['name'], 'UTF-8');

        if (
            str_contains($name, 'customer') ||
            str_contains($name, 'cust') ||
            str_contains($name, 'name') ||
            str_contains($name, 'ten_khach_hang')
        ) {
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

function detectTemplateScenario($textNodes, $params) {
    $combined = mb_strtolower(implode(' ', array_column(getMessageTextNodes($textNodes), 'value')), 'UTF-8');

    if (
        str_contains($combined, 'voucher') ||
        str_contains($combined, 'mã ưu đãi') ||
        str_contains($combined, 'ưu đãi') ||
        str_contains($combined, 'giảm') ||
        str_contains($combined, 'sinh nhật') ||
        str_contains($combined, 'hạng thành viên')
    ) {
        return 'voucher';
    }

    if (
        str_contains($combined, 'khảo sát') ||
        str_contains($combined, 'ý kiến') ||
        str_contains($combined, 'đánh giá') ||
        str_contains($combined, 'cảm nhận') ||
        str_contains($combined, 'cải thiện dịch vụ')
    ) {
        return 'survey';
    }

    if (
        str_contains($combined, 'đặt hẹn') ||
        str_contains($combined, 'lịch hẹn') ||
        str_contains($combined, 'ngày hẹn') ||
        str_contains($combined, 'giờ hẹn') ||
        str_contains($combined, 'nơi hẹn')
    ) {
        return 'appointment';
    }

    if (
        str_contains($combined, 'thanh toán') ||
        str_contains($combined, 'số tiền') ||
        str_contains($combined, 'hạn thanh toán') ||
        str_contains($combined, 'hóa đơn')
    ) {
        return 'payment';
    }

    return 'generic';
}

function buildCustomerRelationshipMessage($textNodes) {
    $scenario = detectTemplateScenario($textNodes, []);

    if ($scenario === 'voucher') {
        return [
            'reason' => 'This voucher message does not clearly identify the recipient as a specific customer or member. For customer-care or promotion-related templates, the reviewer may need to see that the offer is sent to an identifiable user, not a generic audience.',
            'suggestion' => 'Add a customer identifier near the opening line, for example: Quý khách <customer_name> or Mã khách hàng <customer_code>.'
        ];
    }

    if ($scenario === 'survey') {
        return [
            'reason' => 'This feedback/survey message does not clearly show who the survey is intended for. Without a customer identifier, the message may look like a generic mass survey.',
            'suggestion' => 'Add customer identity before asking for feedback, for example: Quý khách <customer_name> or Mã khách hàng <customer_code>.'
        ];
    }

    if ($scenario === 'payment') {
        return [
            'reason' => 'This payment-related message does not clearly identify the payer/customer. Payment notifications usually need enough customer information so the recipient understands the payment belongs to them.',
            'suggestion' => 'Add customer information such as: Quý khách <customer_name>, Mã khách hàng <customer_code>, or Căn hộ <apartment_code>.'
        ];
    }

    return [
        'reason' => 'The message does not clearly show that the recipient is an existing customer, member, or user of the business.',
        'suggestion' => 'Add a customer identifier, for example: Quý khách <customer_name> or Mã khách hàng <customer_code>.'
    ];
}

function buildContextMessage($textNodes) {
    $scenario = detectTemplateScenario($textNodes, []);

    if ($scenario === 'voucher') {
        return [
            'reason' => 'The voucher content shows an offer, but it does not include enough customer/order/contract context to explain why this specific user receives the voucher.',
            'suggestion' => 'Add a concrete context such as: Mã khách hàng <customer_code>, Mã đơn hàng <order_code>, Mã hợp đồng <contract_id>, or Ngày giao dịch <transaction_date>.'
        ];
    }

    if ($scenario === 'survey') {
        return [
            'reason' => 'The message asks for survey or feedback input, but it does not specify which order, service, appointment, or customer interaction triggered the survey.',
            'suggestion' => 'Add service context before the survey request, for example: Quý khách đã sử dụng dịch vụ <service_name> vào ngày <service_date>, mã giao dịch <transaction_id>.'
        ];
    }

    if ($scenario === 'payment') {
        return [
            'reason' => 'The message looks payment-related but does not clearly state the payment period, invoice, contract, apartment, or order that the amount belongs to.',
            'suggestion' => 'Add payment context such as: Mã hợp đồng <contract_id>, Căn hộ <apartment_code>, Kỳ thanh toán <payment_period>, or Mã hóa đơn <invoice_id>.'
        ];
    }

    return [
        'reason' => 'The message does not clearly explain which transaction, service, appointment, report, or activity triggered it.',
        'suggestion' => 'Add context such as: Mã đơn hàng <order_code>, Mã khách hàng <customer_code>, Mã hợp đồng <contract_id>, or Ngày giao dịch <transaction_date>.'
    ];
}

function buildPairMessage($textNodes, $params) {
    $scenario = detectTemplateScenario($textNodes, $params);

    if ($scenario === 'voucher') {
        return [
            'reason' => 'The template identifies the customer, but the voucher message is not paired with a stronger identifier such as customer code, order ID, contract ID, or transaction date. This may not satisfy moderation requirements for customer-identifying parameters.',
            'suggestion' => 'Add a paired identifier near the customer name, for example: Mã khách hàng <customer_code>, Mã đơn hàng <order_code>, Mã hợp đồng <contract_id>, or Ngày giao dịch <transaction_date>.'
        ];
    }

    if ($scenario === 'survey') {
        return [
            'reason' => 'The template identifies the recipient, but the survey/feedback request is not tied to a specific order, service, appointment, transaction, or customer interaction.',
            'suggestion' => 'Add service context before the survey CTA. Example: Quý khách đã sử dụng dịch vụ <service_name> vào ngày <service_date>, mã giao dịch <transaction_id>.'
        ];
    }

    if ($scenario === 'appointment') {
        return [
            'reason' => 'The message identifies the customer, but the appointment context may still need a clear appointment/order/service identifier so the user knows exactly which booking this message refers to.',
            'suggestion' => 'Add appointment identifiers such as: Mã lịch hẹn <booking_id>, Biển số xe <car_id>, Ngày hẹn <date>, or Dịch vụ <service_name>.'
        ];
    }

    if ($scenario === 'payment') {
        return [
            'reason' => 'The message identifies the customer, but the payment request should also be linked to a concrete payment object such as invoice, contract, apartment, payment period, or order.',
            'suggestion' => 'Add payment identifiers such as: Mã hợp đồng <contract_id>, Mã hóa đơn <invoice_id>, Căn hộ <apartment_code>, or Kỳ thanh toán <payment_period>.'
        ];
    }

    return [
        'reason' => 'The template identifies the customer but does not include a specific order, service, appointment, contract, report, or activity identifier.',
        'suggestion' => 'Add a paired identifier, for example: Mã khách hàng <customer_code>, Mã đơn hàng <order_code>, Mã hợp đồng <contract_id>, or Dịch vụ đã sử dụng <service_name>.'
    ];
}

function buildParameterPrefixMessage($param, $text) {
    $lower = mb_strtolower($param, 'UTF-8');
    $context = mb_strtolower($text, 'UTF-8');

    if (str_contains($lower, 'discountdesc')) {
        return [
            'reason' => 'The discount description parameter appears without a clear label. Reviewers may not know whether this value means condition, description, usage rule, or another voucher detail.',
            'suggestion' => "Rewrite as: Điều kiện áp dụng: {$param}."
        ];
    }

    if (str_contains($lower, 'summary')) {
        return [
            'reason' => 'The voucher summary appears as a standalone value without a user-facing label. In the final message, users may not understand that this line describes voucher conditions.',
            'suggestion' => "Rewrite as: Điều kiện áp dụng: {$param}."
        ];
    }

    if (str_contains($lower, 'amount') || str_contains($lower, 'price') || str_contains($lower, 'cost')) {
        if (str_contains($context, 'voucher') || str_contains($context, 'giảm')) {
            return [
                'reason' => 'This amount parameter is related to an offer, but the label around it may not clearly explain whether it is discount value, payment amount, or another amount type.',
                'suggestion' => "Rewrite as: Giá trị ưu đãi: {$param}."
            ];
        }

        return [
            'reason' => 'This amount parameter appears without a clear monetary label, which can make the value ambiguous for users.',
            'suggestion' => "Rewrite as: Số tiền: {$param}."
        ];
    }

    if (str_contains($lower, 'code')) {
        return [
            'reason' => 'This code parameter appears without a clear label, so users may not know whether it is a voucher code, order code, customer code, or another type of code.',
            'suggestion' => "Rewrite as: Mã ưu đãi: {$param}."
        ];
    }

    return [
        'reason' => 'This parameter appears without a clear label, so users may not understand what this value means.',
        'suggestion' => "Add a clear label before the parameter, for example: Mã khách hàng {$param} or Điều kiện áp dụng: {$param}."
    ];
}


function runCustomerRelationshipCheck($textNodes, $params, &$violations) {
    if (hasCustomerSignal($textNodes, $params)) return;

    $target = getMessageTextNodes($textNodes)[0] ?? null;
    $message = buildCustomerRelationshipMessage($textNodes);

    addViolation(
        $violations,
        'CUST_001',
        'Customer relationship is not clearly shown',
        'Customer Relationship',
        $target['location'] ?? 'template.text',
        'No customer/member identifier found',
        $message['reason'],
        $message['suggestion'],
        $target['source_line'] ?? null
    );
}

function runTransactionServiceContextCheck($textNodes, $params, &$violations) {
    $hasCustomer = hasCustomerSignal($textNodes, $params);
    $hasContext = hasStrongTransactionOrServiceContext($textNodes, $params);

    if ($hasContext) return;
    if ($hasCustomer) return;

    $target = findBestContextLocation($textNodes);
    $message = buildContextMessage($textNodes);

    addViolation(
        $violations,
        'CTX_001',
        'Missing transaction or service context',
        'Transaction / Service Context',
        $target['location'],
        $target['value'],
        $message['reason'],
        $message['suggestion'],
        $target['source_line']
    );
}

function runCustomerTransactionPairCheck($textNodes, $params, &$violations) {
    $hasCustomer = hasCustomerSignal($textNodes, $params);
    $hasPair = hasCustomerIdentifierPair($textNodes, $params);

    if (!$hasCustomer || $hasPair) return;

    $target = findBestContextLocation($textNodes);
    $foundCustomer = summarizeCustomerSignals($textNodes, $params);
    $message = buildPairMessage($textNodes, $params);

    addViolation(
        $violations,
        'PAIR_001',
        'Missing customer + transaction/service identifier pair',
        'Customer + Transaction Pair',
        $target['location'],
        "Customer signal found: {$foundCustomer}. Missing paired customer/order/contract/transaction identifier.",
        $message['reason'],
        $message['suggestion'],
        $target['source_line']
    );
}

function runParameterFormatCheck($params, &$violations) {
    $seen = [];

    foreach ($params as $param) {
        $value = $param['value'];

        if (isset($seen[$value])) continue;
        $seen[$value] = true;

        if (!preg_match('/^<[A-Za-z0-9_\$]+>$/u', $value)) {
            addViolation(
                $violations,
                'PARAM_001',
                'Invalid parameter format',
                'Parameter Format',
                $param['location'],
                $value,
                'Parameter names should not contain spaces, Vietnamese accents, hyphens, or unsupported special characters.',
                'Rename it using letters, numbers, and underscores only. Example: <customer_name>.',
                $param['source_line']
            );
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

function findNearbyUtilityLabelForParam($textNodes, $param) {
    if (!isset($param['line']) || $param['line'] === null) return null;

    $paramLine = (int)$param['line'];
    $paramSection = $param['section'] ?? null;

    $labelKeywords = [
        'số tiền thanh toán',
        'số tiền',
        'giá trị',
        'ưu đãi',
        'hạn sử dụng',
        'hsd',
        'ngày hiệu lực',
        'mã ưu đãi',
        'mã voucher',
        'tài khoản',
        'ngân hàng',
        'nội dung chuyển khoản'
    ];

    foreach ($textNodes as $node) {
        if (!isset($node['line']) || $node['line'] === null) continue;

        $nodeLine = (int)$node['line'];

        if ($nodeLine >= $paramLine) continue;
        if (($paramLine - $nodeLine) > 5) continue;

        if ($paramSection !== null && isset($node['section']) && $node['section'] !== $paramSection) continue;

        $nodeText = mb_strtolower(trim($node['value']), 'UTF-8');

        if (preg_match('/<[^>]+>/u', $nodeText)) continue;

        foreach ($labelKeywords as $keyword) {
            if (str_contains($nodeText, $keyword)) return $node['value'];
        }
    }

    return null;
}

function runParameterPrefixClarityCheck($textNodes, $params, &$violations) {
    foreach ($params as $param) {
        $text = $param['text'];
        $value = $param['value'];
        $name = mb_strtolower($param['name'], 'UTF-8');
        $offset = $param['offset'];
        $location = mb_strtolower($param['location'] ?? '', 'UTF-8');

        // 1. Skip map_info value if it already has paired label
        $mapInfoLabel = findMapInfoLabelForParam($textNodes, $param);
        if ($mapInfoLabel !== null && trim($mapInfoLabel) !== '') {
            continue;
        }

        // 2. Skip utility-labelled params
        $nearbyUtilityLabel = findNearbyUtilityLabelForParam($textNodes, $param);
        if ($nearbyUtilityLabel !== null && trim($nearbyUtilityLabel) !== '') {
            continue;
        }

        // 3. Voucher utility params should not be over-flagged
        // Example:
        // Giảm <discount_discountAmount>
        // <discount_summary>
        // HSD: <discount_startDate> - <discount_endDate>
        if (
            str_contains($location, 'open_utility') &&
            (
                $name === 'discount_discountamount' ||
                $name === 'discount_summary' ||
                $name === 'discount_startdate' ||
                $name === 'discount_enddate' ||
                $name === 'voucher_code' ||
                $name === 'expired_date'
            )
        ) {
            continue;
        }

        // 4. Date params are valid if the same line has HSD / expiry wording
        if (
            preg_match('/(HSD|hạn sử dụng|ngày hiệu lực|hiệu lực|từ ngày|đến ngày)/iu', $text) &&
            (
                str_contains($name, 'date') ||
                str_contains($name, 'expired')
            )
        ) {
            continue;
        }

        // 5. Ignore very common low-risk params if they already have natural context
        if (
            in_array($name, [
                'customer_name',
                'membership_tier',
                'discount_discountamount',
                'discount_startdate',
                'discount_enddate',
                'date',
                'time',
                'transfer_amount',
                'bank_transfer_note'
            ], true)
        ) {
            continue;
        }

        $before = mb_substr($text, max(0, $offset - 60), 60, 'UTF-8');

        $hasClearPrefix = preg_match(
            '/(quý khách|khách hàng|mã khách hàng|mã đơn hàng|mã hợp đồng|mã lịch hẹn|mã|tên|hạng|điều kiện|hạn|hsd|số tiền|giá trị|ưu đãi|voucher|ngày|giờ|nơi|dịch vụ|trạng thái|biển số|nội dung hẹn|giảm|tri ân)\s*[:：]?\s*$/iu',
            $before
        );

        // 6. Only flag risky params, not every param
        $isRiskyParam =
            str_contains($name, 'discountdesc') ||
            str_contains($name, 'summary') ||
            str_contains($name, 'amount') ||
            str_contains($name, 'price') ||
            str_contains($name, 'cost') ||
            str_contains($name, 'voucher') ||
            str_contains($name, 'expired') ||
            str_contains($name, 'code');

        if (!$isRiskyParam) {
            continue;
        }

        // 7. Specific case: <discount_discountDesc> directly after amount is ambiguous
        // Example: Voucher <discount_discountAmount><discount_discountDesc>
        if (str_contains($name, 'discountdesc')) {
            $message = [
                'reason' => 'The discount description parameter is attached to the discount amount without a clear label. Reviewers may not know whether this value means condition, discount type, or usage rule.',
                'suggestion' => "Add a clear prefix such as: Điều kiện áp dụng: {$value}."
            ];

            addViolation(
                $violations,
                "PARAM_002",
                "Parameter needs clearer prefix",
                "Parameter Prefix Clarity",
                $param['location'],
                $value,
                $message['reason'],
                $message['suggestion'],
                $param['source_line']
            );

            continue;
        }

        // 8. General risky param without clear prefix
        if (!$hasClearPrefix) {
            $message = buildParameterPrefixMessage($value, $text);

            addViolation(
                $violations,
                "PARAM_002",
                "Parameter needs clearer prefix",
                "Parameter Prefix Clarity",
                $param['location'],
                $value,
                $message['reason'],
                $message['suggestion'],
                $param['source_line']
            );
        }
    }
}

function dynamicPrefixSuggestion($param) {
    $lower = mb_strtolower($param, 'UTF-8');

    if (str_contains($lower, 'discountdesc')) {
        return "Rewrite as: Điều kiện áp dụng: {$param}.";
    }

    if (str_contains($lower, 'summary')) {
        return "Rewrite as: Điều kiện áp dụng: {$param}.";
    }

    if (str_contains($lower, 'amount') || str_contains($lower, 'price') || str_contains($lower, 'cost')) {
        return "Rewrite as: Giá trị ưu đãi: {$param}.";
    }

    if (str_contains($lower, 'voucher') || str_contains($lower, 'code')) {
        return "Rewrite as: Mã ưu đãi: {$param}.";
    }

    if (str_contains($lower, 'date') || str_contains($lower, 'expired')) {
        return "Rewrite as: HSD: {$param}.";
    }

    return "Add a clear label before the parameter, for example: Mã khách hàng {$param} or Điều kiện áp dụng: {$param}.";
}

/* =========================================================
   Preview
========================================================= */

function buildMessagePreview($textNodes, $templateRaw = '') {
    $textNodes = dedupePreviewNodes($textNodes);

    $messageNodes = array_values(array_filter($textNodes, function ($n) {
        return ($n['type'] ?? '') !== 'button'
            && !in_array(($n['role'] ?? ''), ['map_key', 'map_value', 'utility'], true);
    }));

    $buttonNodes = dedupePreviewNodes(getButtonTextNodes($textNodes));
    $mapRows = buildMapInfoRows($textNodes);
    $utilityBox = buildUtilityBox($textNodes);

    $logoImage = extractLogoImageUrl($templateRaw);
    $logoText = inferLogoText($textNodes);

    $title = null;
    $body = [];

    foreach ($messageNodes as $node) {
        if (!$title) {
            $title = previewNode($node);
        } else {
            $body[] = previewNode($node);
        }
    }

    return [
        "logo_text" => $logoText,
        "logo_image_url" => $logoImage,
        "title" => $title,
        "body" => array_slice($body, 0, 8),
        "info_rows" => $mapRows,
        "utility_box" => $utilityBox,
        "buttons" => array_slice(array_map('previewNode', $buttonNodes), 0, 3),
        "raw_nodes" => array_slice($textNodes, 0, 20)
    ];
}

function extractLogoImageUrl($raw) {
    if (!$raw) return null;

    if (preg_match('/"merchantLogoUrl"\s*:\s*""?(https?:\/\/[^"]+)""?/iu', $raw, $m)) {
        return $m[1];
    }

    if (preg_match('/"oa_info"\s*:\s*\{.*?"url"\s*:\s*(?:string)?""?(https?:\/\/[^"]+)""?/us', $raw, $m)) {
        return $m[1];
    }

    if (preg_match('/background-image:\s*url\((https?:\/\/[^)]+)\)/iu', $raw, $m)) {
        return $m[1];
    }

    if (preg_match('/"url"\s*:\s*(?:string)?""?(https?:\/\/[^"]+\.(?:png|jpg|jpeg|webp)[^"]*)""?/iu', $raw, $m)) {
        return $m[1];
    }

    return null;
}

function dedupePreviewNodes($nodes) {
    $seen = [];
    $result = [];

    foreach ($nodes as $node) {
        $text = normalizePreviewText($node['value'] ?? '');
        $role = $node['role'] ?? '';
        $type = $node['type'] ?? '';

        if ($text === '') continue;

        $key = $type . '|' . $role . '|' . $text;

        if (isset($seen[$key])) continue;

        $seen[$key] = true;
        $result[] = $node;
    }

    return $result;
}

function normalizePreviewText($text) {
    $text = mb_strtolower(trim((string)$text), 'UTF-8');
    $text = preg_replace('/\s+/u', ' ', $text);
    return $text;
}

function previewNode($node) {
    return [
        "text" => $node['value'] ?? '',
        "location" => $node['location'] ?? '',
        "line" => $node['line'] ?? null,
        "role" => $node['role'] ?? ''
    ];
}

function buildMapInfoRows($textNodes) {
    $rows = [];

    foreach ($textNodes as $node) {
        if (!preg_match('/map_info\.items\[(\d+)\]\.(key|value)/u', $node['location'], $m)) continue;

        $idx = $m[1];
        $part = $m[2];

        if (!isset($rows[$idx])) {
            $rows[$idx] = [
                "label" => "",
                "value" => "",
                "location" => $node['location']
            ];
        }

        if ($part === 'key') {
            $rows[$idx]['label'] = $node['value'];
        } else {
            $rows[$idx]['value'] = $node['value'];
            $rows[$idx]['location'] = $node['location'];
        }
    }

    $unique = [];
    $result = [];

    foreach ($rows as $row) {
        $key = normalizePreviewText($row['label'] . '|' . $row['value']);

        if ($key === '|') continue;
        if (isset($unique[$key])) continue;

        $unique[$key] = true;
        $result[] = $row;
    }

    return $result;
}

function buildUtilityBox($textNodes) {
    $utilityNodes = array_values(array_filter($textNodes, function ($n) {
        return ($n['role'] ?? '') === 'utility';
    }));

    $utilityNodes = dedupePreviewNodes($utilityNodes);

    if (!count($utilityNodes)) return null;

    $items = array_values(array_map(function ($n) {
        return [
            "text" => $n['value'],
            "location" => $n['location'],
            "line" => $n['line']
        ];
    }, $utilityNodes));

    $title = '';
    $amount = '';
    $details = [];

    foreach ($items as $index => $item) {
        $textLower = mb_strtolower($item['text'], 'UTF-8');

        if ($title === '' && (str_contains($textLower, 'số tiền thanh toán') || str_starts_with($textLower, 'giảm'))) {
            $title = $item['text'];
            $amount = $items[$index + 1]['text'] ?? '';
            $details = array_slice($items, $index + 2);
            break;
        }
    }

    if ($title === '') {
        $title = $items[0]['text'] ?? '';
        $amount = $items[1]['text'] ?? '';
        $details = array_slice($items, 2);
    }

    return [
        "title" => $title,
        "amount" => $amount,
        "details" => array_slice($details, 0, 4)
    ];
}

function inferLogoText($textNodes) {
    $combined = mb_strtolower(implode(' ', array_column($textNodes, 'value')), 'UTF-8');

    if (str_contains($combined, 'bv invest') || str_contains($combined, 'diamond hill')) return 'BVland';
    if (str_contains($combined, 'toyota bến thành')) return 'TOYOTA BẾN THÀNH';
    if (str_contains($combined, 'nam an')) return 'NAM AN';
    if (str_contains($combined, 'lime orange') || str_contains($combined, 'limeorange')) return 'Lime Orange';

    foreach ($textNodes as $node) {
        $text = trim($node['value']);

        if (preg_match('/^(.+?)\s+(xin chào|kính gửi|cảm ơn|thông báo)/iu', $text, $m)) {
            return trim($m[1]);
        }
    }

    return 'OA';
}