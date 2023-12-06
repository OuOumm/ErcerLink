const showMessage = (title, message, type) => $.message({ title, message, type });

const submitLink = (() => {
    let count = parseInt(localStorage.getItem('submitCount')) || 0;
    let lastCallTime = parseInt(localStorage.getItem('lastCallTime')) || 0;
    const threshold = 3; // 第三次调用后的限制时间
    const limitTime = 60000; // 限制时间（毫秒）

    return formId => {
        count++;
        if (count >= threshold) {
            const currentTime = new Date().getTime(); // 获取当前时间戳
            if (currentTime - lastCallTime < limitTime) { // 如果当前时间与上次调用时间小于限制时间
                const remainingTime = Math.floor((lastCallTime + limitTime - currentTime) / 1000);
                showMessage('频繁提交','请等待 ' + remainingTime + ' 秒后再尝试提交','error');
                return;
            }
            count = 0;
            lastCallTime = currentTime;
            localStorage.setItem('lastCallTime', lastCallTime);
        }
        localStorage.setItem('submitCount', count);

        const formData = $('#' + formId).serializeArray();

        $.post("/link_add", formData, data => {
            if (data == '200') {
                showMessage('提交成功', '等待站长通过哟！', 'success');
            } else {
                showMessage('提交失败', data, 'error');
            }
        });
    };
})();
function pjax_Link() {
    $(document).ready(() => {
        $('#postLink').html(`
            <form style="text-align: center;" class="form-inline panel-body" role="form" id="F-link">
                <h4>💞申请友链💞</h4>
                <div class="form-group"> <input type="text" name="host_name" class="form-control" id="host_name" placeholder="站点名称" required=""></div>
                <div class="form-group"> <input type="url" name="host_url" class="form-control" id="host_url" placeholder="站点链接" required=""></div>
                <div class="form-group"> <input type="url" name="host_png" class="form-control" id="host_png" placeholder="站点图标" required=""></div>
                <div class="form-group"> <input type="text" name="host_msg" class="form-control" id="host_msg" placeholder="站点描述（不用太长）" required=""></div>
                <div class="checkbox m-l m-r-xs"><label class="i-checks"><input type="checkbox" name="host_yes" required=""><i></i>已添加本站为友链</label>
                    <button class="btn btn-danger" type="submit">申请</button>
                </div>
            </form>`);
        $('#F-link').submit(event => {
            event.preventDefault();
            submitLink('F-link');
        });
    });
}pjax_Link();