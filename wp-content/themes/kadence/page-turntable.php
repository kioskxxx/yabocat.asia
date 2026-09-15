%5+1
%5+1
<?php
/*
Template Name: 随机转盘模板
*/

// 加载你网站的 WordPress 头部 (Header)
get_header(); 
?>

<link rel="stylesheet" id="turntable-style" href="/Turntable/style.css?v=<?php echo time(); // 加个版本号防止缓存 ?>">

<div id="turntable-app-wrapper">

    <?php
    // 抓取你 index.html 里的 <body> 内容
    // 注意：我们把 h1 和 main-content 都包进来了
    ?>
    <h1 id="mainTitle" contenteditable="true">今天吃什么？</h1>

    <div class="main-content">
        
        <div class="left-column">
            <div class="wheel-container">
                <div class="pointer"></div>
                <canvas id="wheelCanvas" width="500" height="500"></canvas>
            </div>
            <button id="spinBtn">转动！</button>
        </div>

        <div class="right-column">
            <div class="options-container">
                
                <div class="tab-nav">
                    <button id="tab-list" class="tab-link active">查看列表</button>
                    <button id="tab-edit" class="tab-link">编辑选项</button>
                </div>

                <div class="tab-content">
                    
                    <div id="content-list" class="tab-pane active">
                        <ol id="optionsListDisplay"></ol>
                    </div>

                    <div id="content-edit" class="tab-pane">
                        <textarea id="optionsText" rows="10" cols="30"></textarea>
                    </div>
                    
                </div>
                
                <button id="updateBtn">更新转盘</button>
            </div>
        </div>

    </div> </div> <script src="/Turntable/script.js?v=<?php echo time(); // 加个版本号防止缓存 ?>"></script>

<?php
// 加载你网站的 WordPress 尾部 (Footer)
get_footer(); 
?>