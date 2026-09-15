<?php
// 设置时区为中国，确保日期正确
date_default_timezone_set('Asia/Shanghai');

// 开启错误报告，以便在日志中看到详情
error_reporting(E_ALL);
ini_set('display_errors', 1);

// --- 数据库连接信息 ---
$host = 'localhost';
$dbname = 'amazon_stock';
$user = 'amazon_stock';
$pass = 'kiosk123';

echo "Cron Job [Batch Calculate ALL (from tomorrow, latest logic)] Started at: " . date('Y-m-d H:i:s') . "\n";

try {
    $pdo = new PDO("mysql:host=$host;dbname=$dbname;charset=utf8mb4", $user, $pass);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
} catch (Exception $e) {
    die("Database connection failed: " . $e->getMessage() . "\n");
}

// --- [!! 最终版 - 包含所有计算, 模拟从明天开始, 修正取整, 铁路最晚发货新逻辑 !!] ---
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
    if (!empty($railway_date_str)) { try { $railway_arrival_date_obj = new DateTime(str_replace('/', '-', $railway_date_str)); $railway_arrival_date_obj->setTime(0, 0, 0); echo "Railway arrival date parsed: " . $railway_arrival_date_obj->format('Y-m-d') . "\n"; } catch (Exception $e) { echo "Warning: Invalid Railway arrival date format: " . $railway_date_str . "\n"; } }
    else { echo "Info: Railway arrival date not set.\n"; }
    // [!!] 海运到达日期对象 (与 $baseDate 相同)
    $shipping_arrival_date_obj = $baseDate;

    // --- 获取计算铁路前缺货所需的基准日期 ---
    $pre_rail_target_date = null;
    try {
        $stmt_pre_rail_base = $pdo->query("SELECT data FROM amazon_stock_empty_rows ORDER BY id ASC LIMIT 1 OFFSET 3"); // 第4行
        $fourth_row_json = $stmt_pre_rail_base->fetchColumn();
        if ($fourth_row_json) { $fourth_row_data = json_decode($fourth_row_json, true); $pre_rail_base_str = trim($fourth_row_data['海运'] ?? ''); if (!empty($pre_rail_base_str)) { $pre_rail_base_date = new DateTime(str_replace('/', '-', $pre_rail_base_str)); $pre_rail_base_date->setTime(0, 0, 0); $pre_rail_target_date = $pre_rail_base_date->modify('+35 days'); echo "Pre-rail target date calculated: " . $pre_rail_target_date->format('Y-m-d') . "\n"; } else { echo "Warning: Pre-rail base date empty.\n"; } } else { echo "Warning: Could not find 4th remark row.\n"; }
    } catch (Exception $e_pre_rail) { echo "Error getting pre-rail base date: " . $e_pre_rail->getMessage() . "\n"; $pre_rail_target_date = null; }

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
        echo "Error loading activity settings for batch calculation: " . $e_settings->getMessage() . "\n";
        $special_dates_set = []; $isRangeValid = false; $start_date_obj = null; $end_date_obj = null;
        $base_multiplier = 1.0; $total_days_factor = 0; $base_multiplier_factor = 1.0;
    }
    // ===== [!! 统一加载结束 !!] =====


    // --- 3. 遍历所有产品行 ---
    $stmt_rows = $pdo->query("SELECT id, data FROM amazon_stock_rows");
    $rows = $stmt_rows->fetchAll(PDO::FETCH_ASSOC);
    $updateStmt = $pdo->prepare("UPDATE amazon_stock_rows SET data = :data WHERE id = :id");
    $update_count = 0;

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
                $isFirstDayOfShipSim = true; // [!! 标志 !!]

                if ($simStock >= 0) { // 冗余检查，但安全
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
                              
                              if ($days_lasted > 3650) { echo "Warning: Sim exceeded 10 years for row ID {$r['id']}. Resetting.\n"; $days_lasted = 0; break; } 
                              
                              $simDate->modify('+1 day'); 
                              $isFirstDayOfShipSim = false; // 不再是首日
                          }
                          else { 
                              break; // 库存不够
                          }
                     } // 结束 while
                } // 结束 if ($simStock >= 0)
                
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
        $update_count++;
    } // --- 结束遍历所有产品行 ---

    $pdo->commit();
    echo "-------------------------------------------\n";
    echo "Task completed successfully.\n";
    echo "Total rows processed: " . $update_count . "\n";

} catch (Exception $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    die("An error occurred: " . $e->getMessage() . ' on line ' . $e->getLine()); // 添加行号
}
?>