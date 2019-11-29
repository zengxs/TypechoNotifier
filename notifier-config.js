//<![CDATA[
// 等待 jQuery 加载
function defer(method) {
    if (window.jQuery) {
        method();
    } else {
        setTimeout(function () { defer(method) }, 50);
    }
};

defer(function () {
    $(document).ready(function () {
        var typeSelectorMap = {
            "smtp": "ul[id^=typecho-option-item-SMTP]",
            "qqmail": "ul[id^=typecho-option-item-QQMAIL]",
            "qqexmail": "ul[id^=typecho-option-item-QQEXMAIL]",
            "gmail": "ul[id^=typecho-option-item-GMAIL]",
            "outlook": "ul[id^=typecho-option-item-OUTLOOK]",
            "yandex": "ul[id^=typecho-option-item-YANDEX]",
        };

        $('select[name=NotifyType]').change(function () {
            var notifyType = this.value;
            Object.keys(typeSelectorMap).forEach(function (key) {
                var selector = typeSelectorMap[key];
                if (key === notifyType)
                    $(selector).removeClass('hidden');
                else
                    $(selector).addClass('hidden');
            });
            if (notifyType === 'smtp') {
                if ($('input:radio[name=SMTP_auth]:checked').val() === '0') {
                    $('ul[id^=typecho-option-item-SMTP_user]').addClass('hidden');
                    $('ul[id^=typecho-option-item-SMTP_pass]').addClass('hidden');
                } else {
                    $('ul[id^=typecho-option-item-SMTP_user]').removeClass('hidden');
                    $('ul[id^=typecho-option-item-SMTP_pass]').removeClass('hidden');
                }
            }
        });

        $('input:radio[name=SMTP_auth]').change(function () {
            var smtpAuth = $('input:radio[name=SMTP_auth]:checked').val();
            $('ul[id^=typecho-option-item-SMTP_user]').toggleClass('hidden');
            $('ul[id^=typecho-option-item-SMTP_pass]').toggleClass('hidden');
        });

        // 初始化
        $('select[name=NotifyType]').trigger('change');
    });
});
//]]>
