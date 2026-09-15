<?php
/*
Template Name: 亚马逊登录 (全宽)
*/
?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
<meta charset="UTF-8">
<title>亚马逊备货表 - 登录</title>
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<style>
    body {
        font-family: Arial, sans-serif;
        background-color: #f4f4f4;
        display: flex;
        justify-content: center;
        align-items: center;
        height: 100vh;
        margin: 0;
    }
    .login-container {
        background-color: #fff;
        padding: 2rem 3rem;
        border-radius: 8px;
        box-shadow: 0 4px 12px rgba(0,0,0,0.1);
        width: 300px;
        text-align: center;
    }
    h2 {
        color: #333;
        margin-bottom: 1.5rem;
    }
    .input-group {
        margin-bottom: 1rem;
        text-align: left;
    }
    .input-group label {
        display: block;
        margin-bottom: 0.5rem;
        color: #555;
    }
    .input-group input {
        width: 100%;
        padding: 10px;
        border: 1px solid #ccc;
        border-radius: 4px;
        box-sizing: border-box; /* 确保 padding 不会撑开宽度 */
    }
    button {
        width: 100%;
        padding: 10px;
        background-color: #007bff;
        color: white;
        border: none;
        border-radius: 4px;
        cursor: pointer;
        font-size: 16px;
    }
    button:hover {
        background-color: #0056b3;
    }
    #status {
        margin-top: 1rem;
        color: red;
        font-size: 0.9rem;
    }
</style>
%5+1
%5+1
</head>
<body>

<div class="login-container">
    <h2>亚马逊备货表</h2>
    <div class="input-group">
        <label for="username">用户名</label>
        <input type="text" id="username" value="admin">
    </div>
    <div class="input-group">
        <label for="password">密码</label>
        <input type="password" id="password" value="123456" onkeydown="if(event.key==='Enter') login()">
    </div>
    <button onclick="login()">登录</button>
    <p id="status"></p>
</div>

<script>
    // [!! 关键修改 !!]
    // 登录成功后，跳转到您在 WordPress 中创建的 *工具页面* 的地址
    // (请确保这个 URL 与您 WordPress 后台 "亚马逊备货工具" 页面的固定链接一致)
    const WP_TOOL_PAGE_URL = '/index.php/亚马逊备货/';
    
    // (这个 URL 必须指向您旧的 api.php 文件所在的位置)
    const API_URL = '/amazon_stock/api.php';
    // [!! 修改结束 !!]


    const statusEl = document.getElementById('status');
    const userEl = document.getElementById('username');
    const passEl = document.getElementById('password');

    async function login() {
        statusEl.innerText = '登录中...';
        const username = userEl.value;
        const password = passEl.value;

        try {
            const res = await fetch(API_URL, {
                method: 'POST',
                headers: {'Content-Type': 'application/json'},
                body: JSON.stringify({
                    action: 'login',
                    token: 'dummy', // 绕过旧版 token 检查（如果需要）
                    username,
                    password
                })
            });
            const data = await res.json();

            if (data.ok && data.token) {
                localStorage.setItem('token', data.token);
                statusEl.innerText = '✅ 登录成功，正在跳转...';
                
                // [!!] 跳转到新的 WordPress 工具页面
                window.location.href = WP_TOOL_PAGE_URL; 

            } else {
                statusEl.innerText = '❌ ' + (data.error || '登录失败');
            }
        } catch (e) {
            statusEl.innerText = '❌ 网络错误: ' + e.message;
        }
    }
</script>

</body>
</html>