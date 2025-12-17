// 友链申请插件核心逻辑
const ErcerLink = {
    // 显示消息
    showMessage: (title, message, type) => {
        typeof $.message === 'function' ? $.message({ title, message, type }) : console.log(`${type}: ${title} - ${message}`);
    },
    
    // 提交友链申请
    submitLink: (formId) => {
        // 构建表单数据并提交
        $.post("/link_add", $(`#${formId}`).serializeArray(), (data) => {
            ErcerLink.showMessage(
                data === '200' ? '提交成功' : '提交失败',
                data === '200' ? '等待站长通过哟！' : data,
                data === '200' ? 'success' : 'error'
            );
            // 延迟3秒后解锁提交按钮
            setTimeout(() => {
                ErcerLink.isDisableSubmitBtn(false);
            }, 3000);
            // 重新初始化验证码
            turnstile.reset();
        });
    },
    
    // 初始化表单
    initForm: () => {
        let formHTML = `
            <form style="text-align: center;" class="form-inline panel-body" role="form" id="F-link">
                <h4>💞申请友链💞</h4>
                <div class="form-group"> <input type="text" name="host_name" class="form-control" placeholder="站点名称" required></div>
                <div class="form-group"> <input type="url" name="host_url" class="form-control" placeholder="站点链接" required></div>
                <div class="form-group"> <input type="url" name="host_png" class="form-control" placeholder="站点图标" required></div>
                <div class="form-group"> <input type="text" name="host_msg" class="form-control" placeholder="站点描述（不用太长）" required></div>
                <div class="checkbox m-l m-r-xs">
                    <label class="i-checks"><input type="checkbox" name="host_yes" required><i></i>已添加本站为友链</label>
                    <button class="btn btn-danger" type="submit">申请</button>
                </div>
                <!-- code -->
            </form>
        `;

        // 如果开启了验证码，替换占位验证码为实际验证码
        const hasCaptcha = typeof window !== 'undefined' && window.ercerLinkTurnstileSiteKey && window.ercerLinkTurnstileSiteKey.trim() !== '';
        if (hasCaptcha) {
            const sitekey = window.ercerLinkTurnstileSiteKey.trim();
            formHTML = formHTML.replace(
                '<!-- code -->',
                '<div class="form-group" style="margin: 10px 0;">' +
                '<div id="turnstile-widget" class="cf-turnstile" data-sitekey="' + sitekey + '" data-theme="auto" data-size="flexible"></div>' +
                '</div>'
            );
        }
        
        $('#postLink').html(formHTML);

        // 绑定提交事件
        $('#F-link').on('submit', (e) => {
            e.preventDefault();
            if (hasCaptcha) {
                const turnstileResponse = turnstile?.getResponse?.();
                if (!turnstileResponse) {
                    ErcerLink.showMessage('请完成验证码', '请先完成Cloudflare Turnstile验证码', 'error');
                    return;
                }
            }
            ErcerLink.isDisableSubmitBtn(true);
            ErcerLink.submitLink('F-link');
        });
    },
    
    // 禁用或解锁提交按钮
    isDisableSubmitBtn: (disabled) => {
        const submitBtn = document.querySelector('#F-link button[type="submit"]');
        submitBtn && (submitBtn.disabled = disabled);
    },
    
    // 初始化插件
    init: () => {
        // 初始化表单
        ErcerLink.initForm();
    }
};

// 页面加载完成后初始化
$(document).ready(() => ErcerLink.init());

// 支持pjax重新初始化
window.pjax_Link = ErcerLink.init;