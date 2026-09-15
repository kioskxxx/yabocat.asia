<?php
// ===== 计划任务：计算成本 (铁路/海运/安全日/海运最晚) =====

// 开启所有错误报告，以便在宝塔日志中查看问题
error_reporting(E_ALL);
ini_set('display_errors', 1);

date_default_timezone_set('Asia/Shanghai'); // 统一设置时区
echo "Cron Job [Calculate Costs (Railway/Shipping/Safety/ShipDate)] Started at: " . date('Y-m-d H:i:s') . "\n";

// 包含 autoload.php (如果 Phpspreadsheet/vendor 在同一目录)
require_once __DIR__.'/vendor/autoload.php';

// --- 数据库连接 (与 api.php 相同) ---
$host = 'localhost';
$dbname = 'amazon_stock';
$user = 'amazon_stock';
$pass = 'kiosk123';

try {
    $pdo = new PDO("mysql:host=$host;dbname=$dbname;charset=utf8mb4", $user, $pass);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
} catch (Exception $e) {
    die("Database connection failed: " . $e->getMessage() . "\n");
}
// --- 数据库连接结束 ---


// --- [!! 复制自 api.php 的 'calculate_railway_column' 逻辑 !!] ---
$pdo->beginTransaction();
try {
    echo "======= [calculate_railway_column - V8 DEBUG] START =======\n";
    
    $todayObj_calc = new DateTime(date('Y-m-d')); $todayObj_calc->setTime(0,0,0); // [!!] 获取今天日期

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
        echo "[DEBUG] Activity Settings Loaded. Multiplier: {$base_multiplier}, Range Valid: " . ($isRangeValid ? 'Y':'N') . "\n";
    } catch (Exception $e_settings) { 
        echo "[ERROR] Loading Activity Settings FAILED: " . $e_settings->getMessage() . "\n";
        throw new Exception("加载活动设置失败: " . $e_settings->getMessage()); 
    }
    // --- 活动设置加载结束 ---


    // --- B. [!! 核心修正：在后端重新计算所有范围日期 !!] ---
    $rail_col_range_start_obj = null; $rail_col_range_end_obj = null; $is_rail_col_range_valid = false;
    $ship_col_range_start_obj = null; $ship_col_range_end_obj = null; $is_ship_col_range_valid = false;
    $shipping_arrival_date_obj_for_calc = null; // [!!] 用于海运最晚发货的起点
    
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
        echo "[DEBUG] Rail Column Range Calculated: " . $rail_col_range_start_obj->format('Y-m-d') . " to " . $rail_col_range_end_obj->format('Y-m-d') . "\n";
        
        // [!!] 将第5行海运日期存入海运最晚发货的模拟起点
        $shipping_arrival_date_obj_for_calc = $rail_col_range_end_obj;

        // 3. 计算 '海运' 列范围 (第3行海运 和 第5行海运 的 *计算值*)
        $extra_days_r2 = floatval($row2_settings['海运'] ?? 0); // 第2行海运 (数字)
        $total_days_r3 = 70 + $extra_days_r2;
        
        $ship_col_range_start_obj = (clone $base_date_r4)->modify("+" . $total_days_r3 . " days"); // 3行海运 = 4行海运 + (70 + 2行海运)
        $ship_col_range_end_obj = (clone $rail_col_range_end_obj); // 5行海运 (已在上面算出)
        
        // [!! 关键修正：添加调换逻辑 !!]
        if ($ship_col_range_start_obj > $ship_col_range_end_obj) {
            list($ship_col_range_start_obj, $ship_col_range_end_obj) = [$ship_col_range_end_obj, $ship_col_range_start_obj];
            echo "[DEBUG] Shipping Column Range: Start and End were SWAPPED.\n";
        }
        // [!! 修正结束 !!]
        
        $is_ship_col_range_valid = true;
        echo "[DEBUG] Shipping Column Range Calculated: Start=" . $ship_col_range_start_obj->format('Y-m-d') . " (Excluded) to " . $ship_col_range_end_obj->format('Y-m-d') . " (Included)\n";

    } catch (Exception $e_range_calc) {
        echo "[ERROR] Date Range Calculation FAILED: " . $e_range_calc->getMessage() . "\n";
    }
    // --- 范围计算结束 ---

    // --- 4. 获取已处理日期 (用于入库判断) ---
    $stmt_dates_calc = $pdo->query("SELECT processed_date FROM amazon_stock_processed_dates");
    $processed_dates_calc = $stmt_dates_calc->fetchAll(PDO::FETCH_COLUMN);


    // --- 5. 遍历所有产品行 ---
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
        
        // [!! 计算 3: '海运最晚发货' !!]
        $latest_shipping_ship_date = ''; // 初始化
        $safety_stock_for_shipping = $rowData['安全日库存']; // 使用刚算出的值
        
        // 条件：海运范围有效(用于获取起点)，安全日库存 > 0, 日均 > 0
        if ($is_ship_col_range_valid && $safety_stock_for_shipping > 0 && abs($dailyAvg) > 0.001) 
        {
            $simStock_ship = $safety_stock_for_shipping;
            $simDate_ship = clone $shipping_arrival_date_obj_for_calc; // [!!] 使用海运到达日 (第5行海运)
            $days_lasted_ship = 0; 
            $isFirstDayOfSeaSim = true; // 首日不入库

            while (true) {
                $sim_date_key_ship = $simDate_ship->format('Y/n/j');
                $sim_date_sql_ship = $simDate_ship->format('Y-m-d');

                // 入库 (首日不入库)
                if (!$isFirstDayOfSeaSim && isset($rowData[$sim_date_key_ship]) && !in_array($sim_date_sql_ship, $processed_dates_calc)) { // [!!] 使用 $processed_dates_calc
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
                    if ($days_lasted_ship > 3650) { echo "Warning: Ship Sim exceeded 10 years for row ID {$r['id']}. Resetting.\n"; $days_lasted_ship = 0; break; } 
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
             $latest_shipping_ship_date = $todayObj_calc->format('Y-m-d');
        } else {
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
    echo "======= [calculate_railway_column] SUCCESS =======\n";
    echo "'铁路' 列, '海运' 列, '安全日库存', '海运最晚发货' 计算完成，共更新 " . $update_count . " 行。\n";

} catch (Exception $e) {
    if ($pdo->inTransaction()) { $pdo->rollBack(); }
    die("======= [calculate_railway_column] FAILED: " . $e->getMessage() . " on line " . $e->getLine() . " =======\n");
}
// --- [!! 复制逻辑结束 !!] ---

?>