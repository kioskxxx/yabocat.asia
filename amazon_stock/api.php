<?php
error_reporting(0);
header('Content-Type: application/json; charset=utf-8');
session_start();
date_default_timezone_set('Asia/Shanghai'); // 统一设置时区

require_once __DIR__.'/vendor/autoload.php';
use PhpOffice\PhpSpreadsheet\IOFactory;

$host = 'localhost';
$dbname = 'amazon_stock';
$user = 'amazon_stock';
$pass = 'kiosk123';

try {
    $pdo = new PDO("mysql:host=$host;dbname=$dbname;charset=utf8mb4", $user, $pass);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
} catch (Exception $e) {
    echo json_encode(['error' => '数据库连接失败: ' . $e->getMessage()]);
    exit;
}

$raw = file_get_contents("php://input");
if ($_SERVER['REQUEST_METHOD'] === 'POST' && empty($raw) && isset($_FILES['file'])) {
    $data = $_POST;
} else {
    $data = json_decode($raw, true) ?? [];
}

$action = $data['action'] ?? '';
$token = $data['token'] ?? ($_SESSION['token'] ?? null);

// 为计划任务设置的安全密钥
$cron_secret_key = 'YourSecretKey12345';
$is_cron_request = (($action === 'update_daily_stock_auto' || $action === 'calculate_batch_stockout_dates') // Cron 可能调用这两个
                   && ($data['secret'] ?? '') === $cron_secret_key);

// 登录验证
if ($action !== 'login' && !$is_cron_request && $token !== ($_SESSION['token'] ?? '')) {
    echo json_encode(['error' => '未登录']);
    exit;
}

// ===== 登录 =====
if ($action === 'login') {
    $username = $data['username'] ?? '';
    $password = $data['password'] ?? '';
    if ($username === 'yabo' && $password === 'miao') {
        $_SESSION['token'] = bin2hex(random_bytes(16));
        echo json_encode(['ok' => true, 'token' => $_SESSION['token']]);
    } else {
        echo json_encode(['error' => '账号或密码错误']);
    }
    exit;
}

// ===== 获取列 =====
if ($action === 'get_columns') {
    $stmt = $pdo->query("SELECT name FROM amazon_stock_columns ORDER BY id ASC");
    $cols = $stmt->fetchAll(PDO::FETCH_COLUMN);
    echo json_encode(['columns' => $cols]);
    exit;
}

// ===== 获取行 (已升级：包含 processedDates) =====
if ($action === 'get_rows') {
    // 查询所有已处理的日期
    $stmt_dates = $pdo->query("SELECT processed_date FROM amazon_stock_processed_dates");
    $processed_dates = $stmt_dates->fetchAll(PDO::FETCH_COLUMN);

    $stmt_empty = $pdo->query("SELECT data FROM amazon_stock_empty_rows ORDER BY id ASC");
    $emptyRows = [];
    while ($r = $stmt_empty->fetch(PDO::FETCH_ASSOC)) {
        $emptyRows[] = ['data' => json_decode($r['data'], true)];
    }

    $stmt_rows = $pdo->query("SELECT data FROM amazon_stock_rows ORDER BY id ASC");
    $rows = [];
    while ($r = $stmt_rows->fetch(PDO::FETCH_ASSOC)) {
        $rows[] = ['data' => json_decode($r['data'], true)];
    }

    // 在返回的 JSON 中加入 processedDates 数组
    echo json_encode(['emptyRows' => $emptyRows, 'rows' => $rows, 'processedDates' => $processed_dates]);
    exit;
}

// ===== 保存全部 =====
if ($action === 'save_all') {
    $columns = $data['columns'] ?? [];
    $emptyRows = $data['emptyRows'] ?? [];
    $rows = $data['rows'] ?? [];

    try {
        $pdo->beginTransaction();

        // 1. 清理并重新插入列名
        $pdo->exec("DELETE FROM amazon_stock_columns");
        $stmt_col = $pdo->prepare("INSERT INTO amazon_stock_columns (name) VALUES (:name)");
        $currentDbColumns = [];
        foreach ($columns as $col) {
            $trimmedCol = trim($col);
            if ($trimmedCol === '') continue;
            if (!in_array($trimmedCol, $currentDbColumns)) {
                 $stmt_col->execute([':name' => $trimmedCol]);
                 $currentDbColumns[] = $trimmedCol;
            }
        }
        // 确保关键列存在
         $ensureCols = ['铁路到达库存', '铁路最晚发货', '铁路前缺货', '铁路', '海运到达库存'];
         foreach ($ensureCols as $ensureCol) {
              if (!in_array($ensureCol, $currentDbColumns)) {
                   try {
                       $stmt_col->execute([':name' => $ensureCol]);
                       $currentDbColumns[] = $ensureCol;
                   } catch (PDOException $e) {
                       if ($e->getCode() != '23000') { throw $e; }
                       if (!in_array($ensureCol, $currentDbColumns)) { $currentDbColumns[] = $ensureCol; }
                   }
              }
         }


        // 2. 清理并重新插入 emptyRows
        $pdo->exec("DELETE FROM amazon_stock_empty_rows");
        $stmt_empty = $pdo->prepare("INSERT INTO amazon_stock_empty_rows (data) VALUES (:data)");
        foreach ($emptyRows as $row) {
             $rowData = $row['data'] ?? [];
             $finalEmptyRowData = [];
             foreach($currentDbColumns as $dbCol){
                 $finalEmptyRowData[$dbCol] = $rowData[$dbCol] ?? '';
             }
            $stmt_empty->execute([':data' => json_encode($finalEmptyRowData, JSON_UNESCAPED_UNICODE)]);
        }

        // 3. 清理并重新插入 rows (包含 last_update_date 修正)
        $pdo->exec("DELETE FROM amazon_stock_rows");
        $stmt_row = $pdo->prepare("INSERT INTO amazon_stock_rows (data) VALUES (:data)");
        // 使用 $currentDbColumns 作为最终的列列表

        foreach ($rows as $row) {
            $rowData = $row['data'] ?? []; // 原始前端数据
            $finalRowData = []; // 准备存入数据库的数据

            // 确保保存的数据包含所有数据库列
            foreach ($currentDbColumns as $dbCol) {
                $finalRowData[$dbCol] = $rowData[$dbCol] ?? '';
            }

            // 特别处理 last_update_date
            if (isset($rowData['last_update_date'])) {
                if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $rowData['last_update_date'])) {
                     $finalRowData['last_update_date'] = $rowData['last_update_date'];
                }
            }

            $stmt_row->execute([':data' => json_encode($finalRowData, JSON_UNESCAPED_UNICODE)]);
        }

        $pdo->commit();
        echo json_encode(['ok' => true]);

    } catch (Exception $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        echo json_encode(['error' => '保存失败: ' . $e->getMessage() . ' on line ' . $e->getLine()]);
    }
    exit;
}

// ===== Excel 上传 (合并更新版) =====
if ($action === 'upload_excel') {
    try {
        $tmp = $_FILES['file']['tmp_name'] ?? '';
        if (!$tmp) {
            echo json_encode(['error' => '没有检测到上传文件']);
            exit;
        }
        $spreadsheet = IOFactory::load($tmp);
        $sheet = $spreadsheet->getActiveSheet();
        $rows = $sheet->toArray(null, true, true, true);

        if (empty($rows[1])) {
            echo json_encode(['error' => 'Excel没有表头']);
            exit;
        }
        $excelColumns = array_values(array_filter($rows[1]));
        unset($rows[1]);

        $stmt = $pdo->query("SELECT name FROM amazon_stock_columns ORDER BY id ASC");
        $dbColumns = $stmt->fetchAll(PDO::FETCH_COLUMN);
        $finalColumns = $dbColumns;

        // 确保数据库中有 Excel 里的所有列
        foreach ($excelColumns as $c) {
            if (!in_array($c, $finalColumns)) {
                $stmtAdd = $pdo->prepare("INSERT INTO amazon_stock_columns (name) VALUES (:name)");
                $stmtAdd->execute([':name' => $c]);
                $finalColumns[] = $c;
            }
        }
         // 确保新列存在
         $ensureCols = ['铁路到达库存', '铁路最晚发货', '铁路前缺货', '铁路', '海运到达库存'];
         foreach ($ensureCols as $ensureCol) {
             if (!in_array($ensureCol, $finalColumns)) {
                  $stmtAddEnsure = $pdo->prepare("INSERT IGNORE INTO amazon_stock_columns (name) VALUES (:name)");
                  $stmtAddEnsure->execute([':name' => $ensureCol]);
                  $finalColumns[] = $ensureCol;
             }
         }


        $pdo->beginTransaction();
        $checkStmt = $pdo->prepare('SELECT id, data FROM amazon_stock_rows WHERE JSON_EXTRACT(data, \'$.\"店小秘SKU\"\') = ?');
        $updateStmt = $pdo->prepare("UPDATE amazon_stock_rows SET data=:data WHERE id=:id");
        $insertStmt = $pdo->prepare("INSERT INTO amazon_stock_rows (data) VALUES (:data)");

        foreach ($rows as $r) {
            $excelRowData = [];
            foreach ($excelColumns as $index => $colName) {
                $excelRowData[$colName] = $r[chr(65 + $index)] ?? '';
            }
            $sku = $excelRowData['店小秘SKU'] ?? '';
            if ($sku === '') continue;

            $checkStmt->execute([$sku]);
            $existingRow = $checkStmt->fetch(PDO::FETCH_ASSOC);

            if ($existingRow) {
                $oldData = json_decode($existingRow['data'], true) ?: [];
                $mergedData = array_merge($oldData, $excelRowData);
                // 确保所有数据库列都存在
                foreach ($finalColumns as $col) {
                    if (!array_key_exists($col, $mergedData)) $mergedData[$col] = '';
                }
                $updateStmt->execute([':data' => json_encode($mergedData, JSON_UNESCAPED_UNICODE), ':id' => $existingRow['id']]);
            } else {
                $newRowData = [];
                // 确保新行包含所有数据库列
                foreach ($finalColumns as $col) {
                    $newRowData[$col] = $excelRowData[$col] ?? '';
                }
                $insertStmt->execute([':data' => json_encode($newRowData, JSON_UNESCAPED_UNICODE)]);
            }
        }
        $pdo->commit();
        echo json_encode(['ok' => true]);
    } catch (Exception $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        echo json_encode(['error' => '导入失败: ' . $e->getMessage()]);
    }
    exit;
}

// ===== 下载 Excel =====
if ($action === 'download_excel') {
    try {
        $stmt_cols = $pdo->query("SELECT name FROM amazon_stock_columns ORDER BY id ASC");
        $columns = $stmt_cols->fetchAll(PDO::FETCH_COLUMN);

        $stmt_empty = $pdo->query("SELECT data FROM amazon_stock_empty_rows ORDER BY id ASC");
        $stmt_rows = $pdo->query("SELECT data FROM amazon_stock_rows ORDER BY id ASC");

        $spreadsheet = new \PhpOffice\PhpSpreadsheet\Spreadsheet();
        $sheet = $spreadsheet->getActiveSheet();
        $row_index = 1;

        while ($r = $stmt_empty->fetch(PDO::FETCH_ASSOC)) {
            $row_data_decoded = json_decode($r['data'], true);
            $row_data_ordered = [];
            foreach ($columns as $col) $row_data_ordered[] = $row_data_decoded[$col] ?? '';
            $sheet->fromArray($row_data_ordered, NULL, 'A' . $row_index);
            $row_index++;
        }
        $sheet->fromArray($columns, NULL, 'A' . $row_index);
        $row_index++;

        while ($r = $stmt_rows->fetch(PDO::FETCH_ASSOC)) {
            $row_data_decoded = json_decode($r['data'], true);
            $row_data_ordered = [];
            foreach ($columns as $col) $row_data_ordered[] = $row_data_decoded[$col] ?? '';
            $sheet->fromArray($row_data_ordered, NULL, 'A' . $row_index);
            $row_index++;
        }

        $filename = "amazon_stock_" . date('Ymd_His') . ".xlsx";
        if (ob_get_level()) ob_end_clean();
        header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
        header('Content-Disposition: attachment;filename="' . $filename . '"');
        header('Cache-Control: max-age=0');
        $writer = \PhpOffice\PhpSpreadsheet\IOFactory::createWriter($spreadsheet, 'Xlsx');
        $writer->save('php://output');
    } catch (Exception $e) {
        if (!headers_sent()) {
            header_remove();
            header('Content-Type: application/json; charset=utf-8');
            http_response_code(500);
            echo json_encode(['error' => '生成 Excel 失败: ' . $e->getMessage()]);
        }
    }
    exit;
}


// ===== 每日库存更新 (只更新今日库存) =====
if ($action === 'update_daily_stock_auto') {
    $reverse = isset($data['reverse']) ? boolval($data['reverse']) : false;
    $pdo->beginTransaction();
    try {

        // --- 1. 获取最新库存日期 ---
        $stmt_max_date = $pdo->query("SELECT MAX(JSON_UNQUOTE(JSON_EXTRACT(data, '$.last_update_date'))) FROM amazon_stock_rows");
        $current_db_date_str = trim($stmt_max_date->fetchColumn() ?: '', '"');
        if (!$current_db_date_str || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $current_db_date_str)) {
            $current_db_date_str = date('Y-m-d');
        }

        // --- 2. 计算新日期 ---
        $final_update_date = date('Y-m-d', strtotime(($reverse ? '-1 day' : '+1 day'), strtotime($current_db_date_str)));

        // ===== 加载活动日判断所需数据 =====
        $special_dates_set = []; $isRangeValid = false; $start_date_obj = null; $end_date_obj = null; $base_multiplier = 1.0;
        try {
            $stmt_empty_settings = $pdo->query("SELECT data FROM amazon_stock_empty_rows ORDER BY id ASC LIMIT 4 OFFSET 1");
            $empty_rows_settings = $stmt_empty_settings->fetchAll(PDO::FETCH_COLUMN);
            if (count($empty_rows_settings) < 4) throw new Exception("无法加载活动日设置 (行数不足)");
            // [第2行] 特殊日期
            $row2_settings = json_decode($empty_rows_settings[0] ?? '{}', true);
            $special_dates_raw = trim($row2_settings['活动库存'] ?? '');
            if (!empty($special_dates_raw)) { $raw_dates = preg_split('/[\s,]+/', $special_dates_raw, -1, PREG_SPLIT_NO_EMPTY); foreach ($raw_dates as $date_str) { try { $dt = new DateTime(str_replace('/', '-', $date_str)); $special_dates_set[$dt->format('Y-m-d')] = true; } catch (Exception $e) {} } }
            // [第3, 4行] 日期范围
            $row3_settings = json_decode($empty_rows_settings[1] ?? '{}', true);
            $row4_settings = json_decode($empty_rows_settings[2] ?? '{}', true);
            $start_date_str = trim($row3_settings['活动库存'] ?? '');
            $end_date_str = trim($row4_settings['活动库存'] ?? '');
            if (!empty($start_date_str) && !empty($end_date_str)) { try { $start_date_obj = new DateTime(str_replace('/', '-', $start_date_str)); $start_date_obj->setTime(0, 0, 0); $end_date_obj = new DateTime(str_replace('/', '-', $end_date_str)); $end_date_obj->setTime(0, 0, 0); if ($start_date_obj && $end_date_obj && $start_date_obj <= $end_date_obj) { $isRangeValid = true; } } catch (Exception $e) { $isRangeValid = false; } }
            // [第5行] 倍数
            $row5_settings = json_decode($empty_rows_settings[3] ?? '{}', true);
            $multiplier_value = floatval($row5_settings['活动库存'] ?? 1.0);
            $base_multiplier = max(1.0, $multiplier_value);
        } catch (Exception $e_settings) {
            error_log("Error loading activity settings for daily update: " . $e_settings->getMessage());
            $special_dates_set = []; $isRangeValid = false; $start_date_obj = null; $end_date_obj = null; $base_multiplier = 1.0;
        }


        // --- 3. 遍历所有行 ---
        $stmt = $pdo->query("SELECT id, data FROM amazon_stock_rows");
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        $updateStmt = $pdo->prepare("UPDATE amazon_stock_rows SET data = :data WHERE id = :id");

        foreach ($rows as $r) {
            $rowData = json_decode($r['data'], true);
            if (!$rowData) continue;

            $todayStock = floatval($rowData['今日库存'] ?? 0);
            $dailyAvg = floatval($rowData['日均'] ?? 0);

            // --- 判断当天是否活动日 ---
            $check_date_str = ''; $check_date_obj = null; $isActivityDay = false;
            if ($reverse) { $check_date_str = $current_db_date_str; try { $check_date_obj = new DateTime($check_date_str); $check_date_obj->setTime(0,0,0); } catch(Exception $e) {} }
            else { $check_date_str = $final_update_date; try { $check_date_obj = new DateTime($check_date_str); $check_date_obj->setTime(0,0,0); } catch(Exception $e) {} }
            if (isset($special_dates_set[$check_date_str])) { $isActivityDay = true; }
            if (!$isActivityDay && $isRangeValid && $check_date_obj) { if ($check_date_obj >= $start_date_obj && $check_date_obj <= $end_date_obj) { $isActivityDay = true; } }
            // --- 判断结束 ---

            // --- 计算新的 "今日库存" ---
            if ($reverse) { // 减少一天
                $addition = $isActivityDay ? ($dailyAvg * $base_multiplier) : $dailyAvg;
                $rowData['今日库存'] = round($todayStock + $addition, 2);
            } else { // 增加一天
                $deduction = $isActivityDay ? ($dailyAvg * $base_multiplier) : $dailyAvg;
                $potentialNewStock = $todayStock - $deduction;
                $rowData['今日库存'] = max(0, round($potentialNewStock, 2));
            }

            // 统一设置 last_update_date
            $rowData['last_update_date'] = $final_update_date;

            // 写回数据库
            $updateStmt->execute([':data' => json_encode($rowData, JSON_UNESCAPED_UNICODE), ':id' => $r['id']]);
        }

        $pdo->commit();

        echo json_encode(['ok' => true, 'last_update_date' => $final_update_date]);

    } catch (Exception $e) {
        if ($pdo->inTransaction()) { $pdo->rollBack(); }
        echo json_encode(['ok' => false, 'error' => $e->getMessage() . ' on line ' . $e->getLine()]);
    }
    exit;
}
// ===== [!! 关键修改 !!] 一键清空所有计算结果 (包括 0) =====
if ($action === 'clear_stockout_dates') {
    $pdo->beginTransaction();
    try {
        // [!!] 定义所有要清空的计算列
        $keys_to_clear = [
            '缺货日期',
            '铁路到达库存',
            '海运到达库存',
            '铁路前缺货',
            '铁路最晚发货',
            '海运最晚发货',
            '安全日库存',
            '铁路', // 铁路成本列
            '海运'  // 海运成本列
        ];

        $stmt = $pdo->query("SELECT id, data FROM amazon_stock_rows");
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        $updateStmt = $pdo->prepare("UPDATE amazon_stock_rows SET data = :data WHERE id = :id");
        $update_count = 0;
        
        foreach ($rows as $row) {
            $rowData = json_decode($row['data'], true);
            if (!$rowData) continue;
            
            $data_was_changed = false; // 标记是否需要更新
            
            foreach ($keys_to_clear as $key) {
                // [!! 修正逻辑 !!]
                // 只要键存在，并且值不是空字符串，就清空它
                // 这将捕获 "abc", 0, "0", 43.24 等
                if (isset($rowData[$key]) && $rowData[$key] !== '') {
                    $rowData[$key] = ''; // 设置为空字符串
                    $data_was_changed = true;
                }
                // [!! 修正结束 !!]
            }
            
            // 只有在数据实际被修改时才执行更新
            if ($data_was_changed) {
                $updateStmt->execute([':data' => json_encode($rowData, JSON_UNESCAPED_UNICODE), ':id' => $row['id']]);
                $update_count++;
            }
        }
        
        $pdo->commit();
        echo json_encode(['ok' => true, 'message' => "所有计算结果已成功清空 ({$update_count} 行受影响)。"]);
    } catch (Exception $e) {
        $pdo->rollBack();
        echo json_encode(['ok' => false, 'error' => '清空数据时发生错误: ' . $e->getMessage()]);
    }
    exit;
}

// ===== [!! 最终版 - 包含所有计算 + 海运到达库存 + 铁路最晚发货新逻辑 !!] 批量计算 ... =====
if ($action === 'calculate_batch_stockout_dates') {
    $pdo->beginTransaction();
    try {
        // --- 1. 获取截止日期 (海运) 和 铁路到达日期 ---
        $stmt_empty_dates = $pdo->query("SELECT data FROM amazon_stock_empty_rows ORDER BY id ASC LIMIT 1 OFFSET 4"); // 第5行
        $fifth_row_json = $stmt_empty_dates->fetchColumn();
        if (!$fifth_row_json) throw new Exception("无法找到第五行备注行数据");
        $fifth_row_data = json_decode($fifth_row_json, true);
        // 海运截止日期
        $shipping_date_str = trim($fifth_row_data['海运'] ?? '');
        if (empty($shipping_date_str)) throw new Exception("第五行备注行的'海运'列(截止日期)没有填写日期");
        $baseDate = null; try { $baseDate = new DateTime(str_replace('/', '-', $shipping_date_str)); $baseDate->setTime(0, 0, 0); } catch (Exception $e) { throw new Exception("第五行'海运'列(截止日期)的日期格式不正确"); }
        // 铁路到达日期
        $railway_date_str = trim($fifth_row_data['铁路'] ?? '');
        $railway_arrival_date_obj = null;
        if (!empty($railway_date_str)) { try { $railway_arrival_date_obj = new DateTime(str_replace('/', '-', $railway_date_str)); $railway_arrival_date_obj->setTime(0, 0, 0); } catch (Exception $e) { error_log("Invalid Railway arrival date format: " . $railway_date_str); } }
        // [!!] 海运到达日期对象 (与 $baseDate 相同)
        $shipping_arrival_date_obj = $baseDate;

        // --- 获取计算铁路前缺货所需的基准日期 ---
        $pre_rail_target_date = null;
        try {
            $stmt_pre_rail_base = $pdo->query("SELECT data FROM amazon_stock_empty_rows ORDER BY id ASC LIMIT 1 OFFSET 3"); // 第4行
            $fourth_row_json = $stmt_pre_rail_base->fetchColumn();
            if ($fourth_row_json) { $fourth_row_data = json_decode($fourth_row_json, true); $pre_rail_base_str = trim($fourth_row_data['海运'] ?? ''); if (!empty($pre_rail_base_str)) { $pre_rail_base_date = new DateTime(str_replace('/', '-', $pre_rail_base_str)); $pre_rail_base_date->setTime(0, 0, 0); $pre_rail_target_date = $pre_rail_base_date->modify('+35 days'); } }
        } catch (Exception $e_pre_rail) { error_log("Error getting pre-rail base date: " . $e_pre_rail->getMessage()); $pre_rail_target_date = null; }

        // --- 2. 获取所有已处理的入库日期 ---
        $stmt_dates = $pdo->query("SELECT processed_date FROM amazon_stock_processed_dates");
        $processed_dates = $stmt_dates->fetchAll(PDO::FETCH_COLUMN);

        // ===== [!! 统一加载活动设置 !!] =====
        $special_dates_set = []; $isRangeValid = false; $start_date_obj = null; $end_date_obj = null;
        $base_multiplier = 1.0; $total_days_factor = 0; $base_multiplier_factor = 1.0;
        try {
            $stmt_empty_settings = $pdo->query("SELECT data FROM amazon_stock_empty_rows ORDER BY id ASC LIMIT 4 OFFSET 1");
            $empty_rows_settings = $stmt_empty_settings->fetchAll(PDO::FETCH_COLUMN);
            if (count($empty_rows_settings) < 4) throw new Exception("无法加载活动设置 (行数不足)");
            // [第2行] 特殊日期 + 计数
            $special_days_count = 0; $row2_settings = json_decode($empty_rows_settings[0] ?? '{}', true); $special_dates_raw = trim($row2_settings['活动库存'] ?? '');
            if (!empty($special_dates_raw)) { $raw_dates = preg_split('/[\s,]+/', $special_dates_raw, -1, PREG_SPLIT_NO_EMPTY); foreach ($raw_dates as $date_str) { try { $dt = new DateTime(str_replace('/', '-', $date_str)); $special_dates_set[$dt->format('Y-m-d')] = true; $special_days_count++; } catch (Exception $e) {} } }
            // [第3, 4行] 日期范围 + 计算天数
            $inclusive_duration_factor = 0; $row3_settings = json_decode($empty_rows_settings[1] ?? '{}', true); $row4_settings = json_decode($empty_rows_settings[2] ?? '{}', true);
            $start_date_str = trim($row3_settings['活动库存'] ?? ''); $end_date_str = trim($row4_settings['活动库存'] ?? '');
            if (!empty($start_date_str) && !empty($end_date_str)) { try { $start_date_obj_tmp = new DateTime(str_replace('/', '-', $start_date_str)); $start_date_obj_tmp->setTime(0, 0, 0); $end_date_obj_tmp = new DateTime(str_replace('/', '-', $end_date_str)); $end_date_obj_tmp->setTime(0, 0, 0); if ($start_date_obj_tmp && $end_date_obj_tmp && $start_date_obj_tmp <= $end_date_obj_tmp) { $isRangeValid = true; $start_date_obj = $start_date_obj_tmp; $end_date_obj = $end_date_obj_tmp; $interval_factor = $start_date_obj->diff($end_date_obj); $inclusive_duration_factor = $interval_factor->days + 1; } } catch (Exception $e) { $isRangeValid = false; $inclusive_duration_factor = 0; } }
            // [第5行] 倍数
            $row5_settings = json_decode($empty_rows_settings[3] ?? '{}', true); $multiplier_value = floatval($row5_settings['活动库存'] ?? 1.0);
            $base_multiplier = max(1.0, $multiplier_value); $base_multiplier_factor = max(1.0, $multiplier_value);
            // [!!] 计算总天数因子
            $total_days_factor = $special_days_count + $inclusive_duration_factor;
        } catch (Exception $e_settings) {
            error_log("Error loading activity settings for batch calculation: " . $e_settings->getMessage());
            $special_dates_set = []; $isRangeValid = false; $start_date_obj = null; $end_date_obj = null;
            $base_multiplier = 1.0; $total_days_factor = 0; $base_multiplier_factor = 1.0;
        }
        // ===== [!! 统一加载结束 !!] =====


        // --- 3. 遍历所有产品行 ---
        $stmt_rows = $pdo->query("SELECT id, data FROM amazon_stock_rows");
        $rows = $stmt_rows->fetchAll(PDO::FETCH_ASSOC);
        $updateStmt = $pdo->prepare("UPDATE amazon_stock_rows SET data = :data WHERE id = :id");

        $todayObj = new DateTime(date('Y-m-d')); $todayObj->setTime(0,0,0); // 获取今天的日期对象

        foreach ($rows as $r) {
            $rowData = json_decode($r['data'], true);
            if (!$rowData) continue;

            $initial_today_stock = floatval($rowData['今日库存'] ?? 0);
            $calculationStock = $initial_today_stock; // 模拟起始库存仍为今日库存
            $dailyAvg = floatval($rowData['日均'] ?? 0);
            $datesArray = [];
            $railway_arrival_stock_value = null;
            $shipping_arrival_stock_value = null; // [!! 新增 !!]
            $latest_ship_date = '';
            $pre_rail_stockout_count = 0;

            // [!! 修正: round() !!] 计算并设置活动库存 (标准 round)
            $multiplier_adjustment = max(0, $base_multiplier_factor - 1);
            $activity_stock_value = round($total_days_factor * $dailyAvg * $multiplier_adjustment, 2);
            $rowData['活动库存'] = $activity_stock_value;

            // === 正向模拟：计算缺货日期 和 铁路/海运 到达库存 (从明天开始 + 首日不入库) ===
            $loopStartDate = clone $todayObj;
            $loopStartDate->modify('+1 day'); // 从明天开始

            $loopEndDate = clone $baseDate;
            $loopStopDate = (clone $loopEndDate)->modify('+1 day');

             if ($loopStartDate >= $loopStopDate) {
                 if ($railway_arrival_date_obj !== null && $todayObj == $railway_arrival_date_obj) { $railway_arrival_stock_value = $initial_today_stock; }
                 if ($todayObj == $baseDate) { $shipping_arrival_stock_value = $initial_today_stock; }
             } else {
                 $period = new DatePeriod($loopStartDate, new DateInterval('P1D'), $loopStopDate);
                 $isFirstDayOfLoop = true;

                 foreach ($period as $currentDate) {
                     $date_key_format = $currentDate->format('Y/n/j'); $date_sql_format = $currentDate->format('Y-m-d');
                     // 入库 (首日不入库)
                     if (!$isFirstDayOfLoop && isset($rowData[$date_key_format]) && !in_array($date_sql_format, $processed_dates)) { $shipment = floatval($rowData[$date_key_format]); if ($shipment > 0) $calculationStock += $shipment; }
                     // 判断活动日
                     $isActivityDay = false; if (isset($special_dates_set[$date_sql_format])) $isActivityDay = true; if (!$isActivityDay && $isRangeValid && $currentDate >= $start_date_obj && $currentDate <= $end_date_obj) $isActivityDay = true;
                     // 计算扣减
                     $deduction = $isActivityDay ? ($dailyAvg * $base_multiplier) : $dailyAvg;
                     $potentialNewStock = $calculationStock - $deduction;
                     // 更新库存/记录缺货
                     if ($potentialNewStock < 0) {
                          $datesArray[] = $date_sql_format;
                          $calculationStock = 0;
                          if ($pre_rail_target_date !== null && $currentDate < $pre_rail_target_date) { $pre_rail_stockout_count++; }
                     } else {
                          $calculationStock = round($potentialNewStock, 2);
                     }
                     // 记录铁路到达日结束库存
                     if ($railway_arrival_date_obj !== null && $currentDate == $railway_arrival_date_obj && $railway_arrival_stock_value === null) { $railway_arrival_stock_value = $calculationStock; }
                     // [!! 新增 !!] 记录海运到达日结束库存
                     if ($currentDate == $baseDate && $shipping_arrival_stock_value === null) { $shipping_arrival_stock_value = $calculationStock; }
                     
                     $isFirstDayOfLoop = false;
                 }
                 // 特殊处理：如果铁路/海运到达日是今天
                 if ($railway_arrival_date_obj !== null && $todayObj == $railway_arrival_date_obj) { $railway_arrival_stock_value = $initial_today_stock; }
                 if ($todayObj == $baseDate) { $shipping_arrival_stock_value = $initial_today_stock; }
             }
            // === 正向模拟结束 ===

            $rowData['缺货日期'] = implode(',', array_unique($datesArray));
            $rowData['铁路到达库存'] = $railway_arrival_stock_value !== null ? round($railway_arrival_stock_value, 2) : ''; // [!! 修正: round() !!]
            $rowData['海运到达库存'] = $shipping_arrival_stock_value !== null ? round($shipping_arrival_stock_value, 2) : ''; // [!! 修正: round() !!]
            $rowData['铁路前缺货'] = $pre_rail_stockout_count;


            // === [!! 最终修正：铁路最晚发货日期计算 (含入库/首日除外/新条件) !!] ===
            $latest_ship_date = '';
            
            // [!! 新条件 2 !!] 日均为 0
            if (abs($dailyAvg) < 0.001) {
                $latest_ship_date = '不发货';
            }
            // [!!] 检查日期和库存是否有效
            else if ($railway_arrival_date_obj !== null && $railway_arrival_stock_value !== null) {
                
                // 特殊情况：到达时库存 <= 0
                if ($railway_arrival_stock_value <= 0) { 
                    $latest_ship_date = $todayObj->format('Y-m-d'); 
                } 
                // 主模拟：到达时库存 > 0
                else {
                    $simStock = $railway_arrival_stock_value; 
                    $simDate = clone $railway_arrival_date_obj; 
                    $days_lasted = 0;
                    $isFirstDayOfShipSim = true; // 标志：首日不入库

                    while (true) {
                          $sim_date_key = $simDate->format('Y/n/j');
                          $sim_date_sql = $simDate->format('Y-m-d');
                          
                          // 入库 (首日不入库)
                          if (!$isFirstDayOfShipSim && isset($rowData[$sim_date_key]) && !in_array($sim_date_sql, $processed_dates)) {
                               $simShipment = floatval($rowData[$sim_date_key]);
                               if ($simShipment > 0) $simStock += $simShipment;
                          }
                          
                          // 判断活动日
                          $isSimActivity = false; if (isset($special_dates_set[$sim_date_sql])) $isSimActivity = true; if (!$isSimActivity && $isRangeValid && $simDate >= $start_date_obj && $simDate <= $end_date_obj) $isSimActivity = true;
                          $consumption = $isSimActivity ? ($dailyAvg * 3) : $dailyAvg; // 硬编码 3
                          
                          $potentialSimStock = $simStock - $consumption;

                          if ($potentialSimStock >= 0) { 
                              $days_lasted++; 
                              $simStock = round($potentialSimStock, 2); 
                              
                              // [!! 新条件 1 !!] 检查是否达到35天
                              if ($days_lasted >= 35) {
                                  break; // 达到35天，停止模拟
                              }
                              
                              if ($days_lasted > 3650) { error_log("Warning: Sim exceeded 10 years for row ID {$r['id']}. Resetting."); $days_lasted = 0; break; } 
                              
                              $simDate->modify('+1 day'); 
                              $isFirstDayOfShipSim = false; // 不再是首日
                          }
                          else { 
                              break; // 库存不够
                          }
                    } // 结束 while

                    // [!! 新条件 1 !!] 检查最终天数
                    if ($days_lasted >= 35) {
                        $latest_ship_date = '不发货';
                    } else {
                        // 正常计算日期
                        $finalShipDate = clone $todayObj; 
                        if ($days_lasted > 0) { $finalShipDate->modify("+" . $days_lasted . " days"); }
                        $latest_ship_date = $finalShipDate->format('Y-m-d');
                    }
                }
            } else {
                 // 日期无效或库存无效（且日均>0）
                 $latest_ship_date = ''; 
            }
            // === [!! 修正结束 !!] ===

            $rowData['铁路最晚发货'] = $latest_ship_date;

            // [!! 移除 '铁路' 列计算 !!]


            // --- 将包含所有新字段的 $rowData 写回数据库 ---
            $updateStmt->execute([
                ':data' => json_encode($rowData, JSON_UNESCAPED_UNICODE),
                ':id' => $r['id']
            ]);
        } // --- 结束遍历所有产品行 ---

        $pdo->commit();
        echo json_encode(['ok' => true, 'message' => '批量计算完成']);

    } catch (Exception $e) {
        $pdo->rollBack();
        echo json_encode(['ok' => false, 'error' => $e->getMessage() . ' on line ' . $e->getLine()]);
    }
    exit;
}


// ===== [!! 最终修正版 - 合并计算 铁路 + 海运 + 安全日 !!] =====
if ($action === 'calculate_railway_column') { // Action name 保持不变
    $pdo->beginTransaction();
    try {
        error_log("======= [calculate_railway_shipping_column - V8 DEBUG] START =======");

        // --- 1. 独立加载计算所需的所有备注行数据 ---
        $stmt_empty_settings = $pdo->query("SELECT data FROM amazon_stock_empty_rows ORDER BY id ASC LIMIT 5 OFFSET 0"); // 获取第 1, 2, 3, 4, 5 行
        $empty_rows_settings = $stmt_empty_settings->fetchAll(PDO::FETCH_COLUMN);
        if (count($empty_rows_settings) < 5) throw new Exception("无法加载所有备注行 (1-5) 数据");

        // [!!] 解码所有需要的行 [!!]
        $row2_settings = json_decode($empty_rows_settings[1] ?? '{}', true); // 第2行
        $row3_settings = json_decode($empty_rows_settings[2] ?? '{}', true); // 第3行
        $row4_settings = json_decode($empty_rows_settings[3] ?? '{}', true); // 第4行
        $row5_settings = json_decode($empty_rows_settings[4] ?? '{}', true); // 第5行

        // --- A. 加载活动设置 (行 2, 3, 4, 5) ---
        $special_dates_set = []; $isRangeValid = false; $start_date_obj = null; $end_date_obj = null; $base_multiplier = 1.0;
        try {
            // [第2行] 特殊日期
            $special_dates_raw = trim($row2_settings['活动库存'] ?? '');
            if (!empty($special_dates_raw)) { $raw_dates = preg_split('/[\s,]+/', $special_dates_raw, -1, PREG_SPLIT_NO_EMPTY); foreach ($raw_dates as $date_str) { try { $dt = new DateTime(str_replace('/', '-', $date_str)); $special_dates_set[$dt->format('Y-m-d')] = true; } catch (Exception $e) {} } }
            // [第3, 4行] 日期范围
            $start_date_str = trim($row3_settings['活动库存'] ?? ''); $end_date_str = trim($row4_settings['活动库存'] ?? '');
            if (!empty($start_date_str) && !empty($end_date_str)) { try { $start_date_obj_tmp = new DateTime(str_replace('/', '-', $start_date_str)); $start_date_obj_tmp->setTime(0, 0, 0); $end_date_obj_tmp = new DateTime(str_replace('/', '-', $end_date_str)); $end_date_obj_tmp->setTime(0, 0, 0); if ($start_date_obj_tmp && $end_date_obj_tmp && $start_date_obj_tmp <= $end_date_obj_tmp) { $isRangeValid = true; $start_date_obj = $start_date_obj_tmp; $end_date_obj = $end_date_obj_tmp; } } catch (Exception $e) { $isRangeValid = false; } }
            // [第5行] 倍数
            $multiplier_value = floatval($row5_settings['活动库存'] ?? 1.0);
            $base_multiplier = max(1.0, $multiplier_value);
            error_log("[DEBUG] Activity Settings Loaded. Multiplier: {$base_multiplier}, Range Valid: " . ($isRangeValid ? 'Y':'N'));
        } catch (Exception $e_settings) { 
            error_log("[ERROR] Loading Activity Settings FAILED: " . $e_settings->getMessage());
            throw new Exception("加载活动设置失败: " . $e_settings->getMessage()); 
        }
        // --- 活动设置加载结束 ---


        // --- B. [!! 核心修正：在后端重新计算所有范围日期 !!] ---
        $rail_col_range_start_obj = null; $rail_col_range_end_obj = null; $is_rail_col_range_valid = false;
        $ship_col_range_start_obj = null; $ship_col_range_end_obj = null; $is_ship_col_range_valid = false;
        $todayObj_calc = new DateTime(date('Y-m-d')); $todayObj_calc->setTime(0,0,0); // [!!] 获取今天日期
        
        try {
            // 1. 获取基准日期 (第4行海运)
            $base_date_str_r4 = trim($row4_settings['海运'] ?? '');
            if (empty($base_date_str_r4)) throw new Exception("第4行 '海运' (基准日期) 未填写");
            $base_date_r4 = new DateTime(str_replace('/', '-', $base_date_str_r4)); 
            $base_date_r4->setTime(0,0,0);

            // 2. 计算 '铁路' 列范围 (第5行铁路 和 第5行海运 的 *计算值*)
            $rail_col_range_start_obj = (clone $base_date_r4)->modify('+35 days'); // 5行铁路 = 4行海运 + 35
            $rail_col_range_end_obj = (clone $base_date_r4)->modify('+70 days');   // 5行海运 = 4行海运 + 70
            $is_rail_col_range_valid = true; // 35 总是 <= 70
            error_log("[DEBUG] Rail Column Range Calculated: " . $rail_col_range_start_obj->format('Y-m-d') . " to " . $rail_col_range_end_obj->format('Y-m-d'));

            // 3. 计算 '海运' 列范围 (第3行海运 和 第5行海运 的 *计算值*)
            $extra_days_r2 = floatval($row2_settings['海运'] ?? 0); // 第2行海运 (数字)
            $total_days_r3 = 70 + $extra_days_r2;
            
            $ship_col_range_start_obj = (clone $base_date_r4)->modify("+" . $total_days_r3 . " days"); // 3行海运 = 4行海运 + (70 + 2行海运)
            $ship_col_range_end_obj = (clone $rail_col_range_end_obj); // 5行海运 (已在上面算出)
            
            // [!! 关键修正：添加调换逻辑 !!]
            if ($ship_col_range_start_obj > $ship_col_range_end_obj) {
                list($ship_col_range_start_obj, $ship_col_range_end_obj) = [$ship_col_range_end_obj, $ship_col_range_start_obj];
                error_log("[DEBUG] Shipping Column Range: Start and End were SWAPPED.");
            }
            // [!! 修正结束 !!]
            
            $is_ship_col_range_valid = true;
            error_log("[DEBUG] Shipping Column Range Calculated: Start=" . $ship_col_range_start_obj->format('Y-m-d') . " (Excluded) to " . $ship_col_range_end_obj->format('Y-m-d') . " (Included)");

        } catch (Exception $e_range_calc) {
            error_log("[ERROR] Date Range Calculation FAILED: " . $e_range_calc->getMessage());
        }
        // --- 范围计算结束 ---


        // --- 3. 遍历所有产品行 ---
        $stmt_rows = $pdo->query("SELECT id, data FROM amazon_stock_rows");
        $rows = $stmt_rows->fetchAll(PDO::FETCH_ASSOC);
        $updateStmt = $pdo->prepare("UPDATE amazon_stock_rows SET data = :data WHERE id = :id");
        $update_count = 0;

        foreach ($rows as $r) {
            $rowData = json_decode($r['data'], true);
            if (!$rowData) continue;

            $dailyAvg = floatval($rowData['日均'] ?? 0);
            $stockout_dates_str = $rowData['缺货日期'] ?? '';
            $stockout_dates_list = [];
            if (!empty($stockout_dates_str)) {
                 $stockout_dates_list = array_filter(array_map('trim', explode(',', $stockout_dates_str)));
            }

            // [!! 计算 1: '铁路' 列 !!]
            $normal_days_rail = 0;
            $activity_days_rail = 0;
            $new_railway_value = 0.0;
            if (abs($dailyAvg) > 0.001 && $is_rail_col_range_valid) { 
                 foreach ($stockout_dates_list as $stockout_date_str) {
                      try {
                           $stockout_date_obj = new DateTime($stockout_date_str); $stockout_date_obj->setTime(0,0,0);
                           if ($stockout_date_obj >= $rail_col_range_start_obj && $stockout_date_obj <= $rail_col_range_end_obj) {
                                $isStockoutActivity = false;
                                if (isset($special_dates_set[$stockout_date_str])) $isStockoutActivity = true;
                                if (!$isStockoutActivity && $isRangeValid && $stockout_date_obj >= $start_date_obj && $stockout_date_obj <= $end_date_obj) $isStockoutActivity = true;
                                if ($isStockoutActivity) { $activity_days_rail++; } else { $normal_days_rail++; }
                           }
                      } catch (Exception $e) {}
                 }
                 $new_railway_value = ($normal_days_rail * $dailyAvg) + ($activity_days_rail * $dailyAvg * $base_multiplier);
            }
            $rowData['铁路'] = ceil($new_railway_value); // 向上取整

            // [!! 计算 2: '海运' 列 和 '安全日库存' !!]
            $shipping_arrival_stock = floatval($rowData['海运到达库存'] ?? 0); // 这是输入
            $normal_days_ship = 0;
            $activity_days_ship = 0;
            $total_consumption_value = 0.0; // 这是 总值
            
            if (abs($dailyAvg) > 0.001 && $is_ship_col_range_valid) { 
                 $shipPeriodStart = (clone $ship_col_range_start_obj)->modify('+1 day');
                 $shipPeriodStop = (clone $ship_col_range_end_obj)->modify('+1 day');
                 
                 if ($shipPeriodStart < $shipPeriodStop) {
                     $shipPeriod = new DatePeriod($shipPeriodStart, new DateInterval('P1D'), $shipPeriodStop);
                     
                     foreach($shipPeriod as $currentDate) {
                          $current_date_sql = $currentDate->format('Y-m-d');
                          $isActivity = false;
                          if (isset($special_dates_set[$current_date_sql])) $isActivity = true;
                          if (!$isActivity && $isRangeValid && $currentDate >= $start_date_obj && $currentDate <= $end_date_obj) $isActivity = true;

                          if ($isActivity) { $activity_days_ship++; }
                          else { $normal_days_ship++; } // "普通日"
                     }
                 }
                 $total_consumption_value = ($normal_days_ship * $dailyAvg) + ($activity_days_ship * $dailyAvg * $base_multiplier);
            }
            
            $result = $shipping_arrival_stock - $total_consumption_value;
            
            if ($result > 0) {
                $rowData['海运'] = 0;
                $rowData['安全日库存'] = round($result, 2); 
            } else if ($result < 0) {
                $rowData['海运'] = ceil(abs($result)); // 向上取整
                $rowData['安全日库存'] = 0;
            } else { // $result == 0
                $rowData['海运'] = 0;
                $rowData['安全日库存'] = 0;
            }

            // [!! 新增：计算 '海运最晚发货' !!]
            $latest_shipping_ship_date = ''; // 初始化
            $safety_stock_for_shipping = $rowData['安全日库存']; // 使用刚算出的值
            
            // 条件：海运范围有效，安全日库存 > 0, 日均 > 0
            // [!!] $ship_col_range_end_obj 是海运到达日
            if ($is_ship_col_range_valid && $safety_stock_for_shipping > 0 && abs($dailyAvg) > 0.001) 
            {
                $simStock_ship = $safety_stock_for_shipping;
                $simDate_ship = clone $ship_col_range_end_obj; // [!!] 模拟从 '第5行海运' 日期开始
                $days_lasted_ship = 0; 
                $isFirstDayOfSeaSim = true; // 首日不入库

                while (true) {
                    $sim_date_key_ship = $simDate_ship->format('Y/n/j');
                    $sim_date_sql_ship = $simDate_ship->format('Y-m-d');

                    // 入库 (首日不入库)
                    if (!$isFirstDayOfSeaSim && isset($rowData[$sim_date_key_ship]) && !in_array($sim_date_sql_ship, $processed_dates)) {
                         $simShipment = floatval($rowData[$sim_date_key_ship]);
                         if ($simShipment > 0) $simStock_ship += $simShipment;
                    }

                    // 判断活动日
                    $isSimActivityShip = false; 
                    if (isset($special_dates_set[$sim_date_sql_ship])) $isSimActivityShip = true; 
                    if (!$isSimActivityShip && $isRangeValid && $simDate_ship >= $start_date_obj && $simDate_ship <= $end_date_obj) $isSimActivityShip = true;
                    
                    // 计算消耗 (硬编码 3)
                    $consumptionShip = $isSimActivityShip ? ($dailyAvg * 3) : $dailyAvg;
                    
                    // 计算扣减后
                    $potentialSimStockShip = $simStock_ship - $consumptionShip;

                    // 计数逻辑
                    if ($potentialSimStockShip >= 0) {
                        $days_lasted_ship++; 
                        $simStock_ship = round($potentialSimStockShip, 2); 
                        if ($days_lasted_ship > 3650) { error_log("Warning: Ship Sim exceeded 10 years for row ID {$r['id']}. Resetting."); $days_lasted_ship = 0; break; } 
                        $simDate_ship->modify('+1 day');
                        $isFirstDayOfSeaSim = false; // 不再是首日
                    } else {
                        break; // 库存不够，停止
                    }
                } // 结束 while

                // 计算最终日期
                $finalShippingShipDate = clone $todayObj_calc; 
                if ($days_lasted_ship > 0) { 
                     $finalShippingShipDate->modify("+" . $days_lasted_ship . " days"); 
                }
                $latest_shipping_ship_date = $finalShippingShipDate->format('Y-m-d');

            } else if ($is_ship_col_range_valid && $safety_stock_for_shipping <= 0 && abs($dailyAvg) > 0.001) {
                 // 如果库存 <= 0 但 日均 > 0
                 $latest_shipping_ship_date = $todayObj_calc->format('Y-m-d');
            } else {
                 // 日均为0，或范围无效
                 $latest_shipping_ship_date = '';
            }

            $rowData['海运最晚发货'] = $latest_shipping_ship_date;
            // === [!! 新增结束 !!] ===

            // --- 写回数据库 ---
            $updateStmt->execute([
                ':data' => json_encode($rowData, JSON_UNESCAPED_UNICODE),
                ':id' => $r['id']
            ]);
            $update_count++;
        } // --- 结束遍历所有产品行 ---

        $pdo->commit();
        error_log("======= [calculate_railway_column] SUCCESS =======");
        echo json_encode(['ok' => true, 'message' => "'铁路', '海运', '安全日库存', '海运最晚发货' 计算完成，共更新 " . $update_count . " 行。"]);

    } catch (Exception $e) {
        if ($pdo->inTransaction()) { $pdo->rollBack(); }
        error_log("======= [calculate_railway_column] FAILED: " . $e->getMessage() . " on line " . $e->getLine() . " =======");
        echo json_encode(['ok' => false, 'error' => $e->getMessage() . ' on line ' . $e->getLine()]);
    }
    exit;
}
// ===== [!! 已移除 !!] 独立的 calculate_pre_rail_stockout 功能 =====

// ===== [新功能] 删除所有行中的 "铁路路前缺货" 键 =====
if ($action === 'remove_redundant_key') {
    $pdo->beginTransaction();
    try {
        $key_to_remove = '铁路路前缺货';
        $stmt_rows = $pdo->query("SELECT id, data FROM amazon_stock_rows");
        $updateStmt = $pdo->prepare("UPDATE amazon_stock_rows SET data = :data WHERE id = :id");
        $update_count = 0;
        while ($row = $stmt_rows->fetch(PDO::FETCH_ASSOC)) {
            $rowData = json_decode($row['data'], true);
            if (isset($rowData[$key_to_remove])) {
                unset($rowData[$key_to_remove]);
                $updateStmt->execute([':data' => json_encode($rowData, JSON_UNESCAPED_UNICODE),':id' => $row['id']]);
                $update_count++;
            }
        }
        $pdo->commit();
        echo json_encode(['ok' => true, 'message' => "操作成功，共清理了 " . $update_count . " 行数据。"]);
    } catch (Exception $e) {
        $pdo->rollBack();
        echo json_encode(['ok' => false, 'error' => '删除数据时发生错误: ' . $e->getMessage()]);
    }
    exit;
}

// ===== [!! 新增功能：铁路发货 (已修正 0 值处理 + 覆盖逻辑) !!] =====
if ($action === 'process_railway_shipment') {
    $pdo->beginTransaction();
    try {
        // 1. 获取第5行 '铁路' 日期
        $stmt_empty_date = $pdo->query("SELECT data FROM amazon_stock_empty_rows ORDER BY id ASC LIMIT 1 OFFSET 4"); // 第5行
        $fifth_row_json = $stmt_empty_date->fetchColumn();
        if (!$fifth_row_json) throw new Exception("无法找到第五行备注行数据");
        
        $fifth_row_data = json_decode($fifth_row_json, true);
        $new_column_name = trim($fifth_row_data['铁路'] ?? ''); // 这就是新列名, e.g., "2025/11/25"

        if (empty($new_column_name)) {
            throw new Exception("第五行 '铁路' 列日期为空，无法创建新列");
        }
        // 简单验证日期格式
        if (!preg_match('/^\d{4}[\/-]\d{1,2}[\/-]\d{1,2}$/', $new_column_name)) {
             throw new Exception("第五行 '铁路' 列日期格式不正确，应为 YYYY/M/D 或 YYYY-MM-DD");
        }

        // 2. [!! 修正 !!] 检查新列是否已存在
        $checkStmt = $pdo->prepare("SELECT COUNT(*) FROM amazon_stock_columns WHERE name = :name");
        $checkStmt->execute([':name' => $new_column_name]);
        $column_exists = ($checkStmt->fetchColumn() > 0);
        $message = ""; // 初始化消息

        if (!$column_exists) {
            // 3. 如果不存在，添加新列到 amazon_stock_columns
            $stmt_add_col = $pdo->prepare("INSERT INTO amazon_stock_columns (name) VALUES (:name)");
            $stmt_add_col->execute([':name' => $new_column_name]);
            
            // 5. 并更新所有 amazon_stock_empty_rows (添加新键)
            $stmt_empty_rows = $pdo->query("SELECT id, data FROM amazon_stock_empty_rows");
            $updateEmptyStmt = $pdo->prepare("UPDATE amazon_stock_empty_rows SET data = :data WHERE id = :id");
            while ($er = $stmt_empty_rows->fetch(PDO::FETCH_ASSOC)) {
                $emptyRowData = json_decode($er['data'], true);
                $emptyRowData[$new_column_name] = ''; // 添加新键，值为空
                $updateEmptyStmt->execute([':data' => json_encode($emptyRowData, JSON_UNESCAPED_UNICODE), ':id' => $er['id']]);
            }
            $message = "铁路发货已处理，新列 '{$new_column_name}' 已创建";
        } else {
            // 3b. 如果已存在，设置覆盖消息
             $message = "铁路发货已处理，已覆盖原有列 '{$new_column_name}' 的数据";
        }
        // [!! 修正结束 !!]


        // 4. [!!] 更新所有 amazon_stock_rows (无论列是否存在)
        $stmt_rows = $pdo->query("SELECT id, data FROM amazon_stock_rows");
        $updateStmt = $pdo->prepare("UPDATE amazon_stock_rows SET data = :data WHERE id = :id");
        $update_count_rows = 0;
        while ($row = $stmt_rows->fetch(PDO::FETCH_ASSOC)) {
            $rowData = json_decode($row['data'], true);
            $railway_value = $rowData['铁路'] ?? ''; // 获取 '铁路' 列的值
            
            // 检查值是否为 0 (包括 '0', 0, 0.0 等)
            if (floatval($railway_value) == 0) {
                $rowData[$new_column_name] = ''; // 如果是 0，则新列设为空字符串
            } else {
                $rowData[$new_column_name] = $railway_value; // 否则，复制原值
            }

            $rowData['铁路'] = ''; // 无论如何都清空原 '铁路' 列
            
            $updateStmt->execute([':data' => json_encode($rowData, JSON_UNESCAPED_UNICODE), ':id' => $row['id']]);
            $update_count_rows++;
        }
        
        $pdo->commit();
        echo json_encode(['ok' => true, 'message' => $message . "，共更新 {$update_count_rows} 行产品。"]);

    } catch (Exception $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        echo json_encode(['ok' => false, 'error' => $e->getMessage() . ' on line ' . $e->getLine()]);
    }
    exit;
}

// ===== [!! 新增功能：海运发货 !!] =====
if ($action === 'process_shipping_shipment') {
    $pdo->beginTransaction();
    try {
        // 1. 获取第5行 '海运' 日期
        $stmt_empty_date = $pdo->query("SELECT data FROM amazon_stock_empty_rows ORDER BY id ASC LIMIT 1 OFFSET 4"); // 第5行
        $fifth_row_json = $stmt_empty_date->fetchColumn();
        if (!$fifth_row_json) throw new Exception("无法找到第五行备注行数据");
        
        $fifth_row_data = json_decode($fifth_row_json, true);
        $new_column_name = trim($fifth_row_data['海运'] ?? ''); // [!!] 源: 海运日期

        if (empty($new_column_name)) {
            throw new Exception("第五行 '海运' 列日期为空，无法创建新列");
        }
        if (!preg_match('/^\d{4}[\/-]\d{1,2}[\/-]\d{1,2}$/', $new_column_name)) {
             throw new Exception("第五行 '海运' 列日期格式不正确，应为 YYYY/M/D 或 YYYY-MM-DD");
        }

        // 2. 检查新列是否已存在
        $checkStmt = $pdo->prepare("SELECT COUNT(*) FROM amazon_stock_columns WHERE name = :name");
        $checkStmt->execute([':name' => $new_column_name]);
        $column_exists = ($checkStmt->fetchColumn() > 0);
        $message = "";

        if (!$column_exists) {
            // 3. 如果不存在，添加新列
            $stmt_add_col = $pdo->prepare("INSERT INTO amazon_stock_columns (name) VALUES (:name)");
            $stmt_add_col->execute([':name' => $new_column_name]);
            
            // 5. 并更新 empty_rows
            $stmt_empty_rows = $pdo->query("SELECT id, data FROM amazon_stock_empty_rows");
            $updateEmptyStmt = $pdo->prepare("UPDATE amazon_stock_empty_rows SET data = :data WHERE id = :id");
            while ($er = $stmt_empty_rows->fetch(PDO::FETCH_ASSOC)) {
                $emptyRowData = json_decode($er['data'], true);
                $emptyRowData[$new_column_name] = ''; // 添加新键
                $updateEmptyStmt->execute([':data' => json_encode($emptyRowData, JSON_UNESCAPED_UNICODE), ':id' => $er['id']]);
            }
            $message = "海运发货已处理，新列 '{$new_column_name}' 已创建";
        } else {
             $message = "海运发货已处理，已覆盖原有列 '{$new_column_name}' 的数据";
        }

        // 4. 更新所有 amazon_stock_rows
        $stmt_rows = $pdo->query("SELECT id, data FROM amazon_stock_rows");
        $updateStmt = $pdo->prepare("UPDATE amazon_stock_rows SET data = :data WHERE id = :id");
        $update_count_rows = 0;
        while ($row = $stmt_rows->fetch(PDO::FETCH_ASSOC)) {
            $rowData = json_decode($row['data'], true);
            $shipping_value = $rowData['海运'] ?? ''; // [!!] 源: 海运列
            
            if (floatval($shipping_value) == 0) {
                $rowData[$new_column_name] = ''; // 0 变 空
            } else {
                $rowData[$new_column_name] = $shipping_value; // 复制
            }

            $rowData['海运'] = ''; // [!!] 清空: 海运列
            
            $updateStmt->execute([':data' => json_encode($rowData, JSON_UNESCAPED_UNICODE), ':id' => $row['id']]);
            $update_count_rows++;
        }
        
        $pdo->commit();
        echo json_encode(['ok' => true, 'message' => $message . "，共更新 {$update_count_rows} 行产品。"]);

    } catch (Exception $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        echo json_encode(['ok' => false, 'error' => $e->getMessage() . ' on line ' . $e->getLine()]);
    }
    exit;
}

// ===== [!! 新增功能：删除所有日期列 !!] =====
if ($action === 'delete_all_date_columns') {
    $pdo->beginTransaction();
    try {
        // 1. 查找所有日期格式的列
        $stmt_cols = $pdo->query("SELECT name FROM amazon_stock_columns");
        $all_columns = $stmt_cols->fetchAll(PDO::FETCH_COLUMN);
        
        $date_columns_to_delete = [];
        $date_regex = '/^\d{4}[\/-]\d{1,2}[\/-]\d{1,2}$/';
        foreach ($all_columns as $col) {
            if (preg_match($date_regex, $col)) {
                $date_columns_to_delete[] = $col;
            }
        }

        if (empty($date_columns_to_delete)) {
            $pdo->commit();
            echo json_encode(['ok' => true, 'message' => '没有找到需要删除的日期列。']);
            exit;
        }

        // 2. 从 amazon_stock_columns 删除
        $placeholders = implode(',', array_fill(0, count($date_columns_to_delete), '?'));
        $stmt_delete_cols = $pdo->prepare("DELETE FROM amazon_stock_columns WHERE name IN ($placeholders)");
        $stmt_delete_cols->execute($date_columns_to_delete);
        $deleted_col_count = $stmt_delete_cols->rowCount();

        // 3. 从 amazon_stock_rows 更新 (移除键)
        $stmt_rows = $pdo->query("SELECT id, data FROM amazon_stock_rows");
        $updateStmt_rows = $pdo->prepare("UPDATE amazon_stock_rows SET data = :data WHERE id = :id");
        $update_count_rows = 0;
        while ($row = $stmt_rows->fetch(PDO::FETCH_ASSOC)) {
            $rowData = json_decode($row['data'], true);
            $data_changed = false;
            foreach ($date_columns_to_delete as $colName) {
                if (isset($rowData[$colName])) {
                    unset($rowData[$colName]);
                    $data_changed = true;
                }
            }
            if ($data_changed) {
                $updateStmt_rows->execute([':data' => json_encode($rowData, JSON_UNESCAPED_UNICODE), ':id' => $row['id']]);
                $update_count_rows++;
            }
        }
        
        // 4. 从 amazon_stock_empty_rows 更新 (移除键)
        $stmt_empty_rows = $pdo->query("SELECT id, data FROM amazon_stock_empty_rows");
        $updateStmt_empty = $pdo->prepare("UPDATE amazon_stock_empty_rows SET data = :data WHERE id = :id");
        while ($er = $stmt_empty_rows->fetch(PDO::FETCH_ASSOC)) {
            $emptyRowData = json_decode($er['data'], true);
            $data_changed_empty = false;
            foreach ($date_columns_to_delete as $colName) {
                if (isset($emptyRowData[$colName])) {
                    unset($emptyRowData[$colName]);
                    $data_changed_empty = true;
                }
            }
            if ($data_changed_empty) {
                $updateStmt_empty->execute([':data' => json_encode($emptyRowData, JSON_UNESCAPED_UNICODE), ':id' => $er['id']]);
            }
        }

        $pdo->commit();
        echo json_encode(['ok' => true, 'message' => "操作成功。共删除了 {$deleted_col_count} 个日期列表头，并从 {$update_count_rows} 行产品数据中移除了这些日期的数据。"]);

    } catch (Exception $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        echo json_encode(['ok' => false, 'error' => $e->getMessage() . ' on line ' . $e->getLine()]);
    }
    exit;
}

// ===== [!! 新增功能：删除所有产品行 (TRUNCATE) - 修正版 !!] =====
if ($action === 'truncate_data_rows') {
    // [!! 修正：移除事务 !!]
    // TRUNCATE TABLE 会导致隐式提交，从而破坏事务
    // $pdo->beginTransaction(); 
    try {
        // TRUNCATE TABLE 速度远快于 DELETE FROM
        $pdo->exec("TRUNCATE TABLE amazon_stock_rows");
        
        // $pdo->commit(); // [!! 移除 !!]
        echo json_encode(['ok' => true, 'message' => '已成功删除所有产品行数据。']);

    } catch (Exception $e) {
        // [!! 移除事务检查 !!]
        // if ($pdo->inTransaction()) $pdo->rollBack();
        echo json_encode(['ok' => false, 'error' => $e->getMessage() . ' on line ' . $e->getLine()]);
    }
    exit;
}

// ===== 如果没有任何 action 匹配，则返回错误 =====
echo json_encode(['error' => '未知操作: ' . htmlspecialchars($action)]);
exit;
?>