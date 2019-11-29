<?php
if (!defined('__TYPECHO_ROOT_DIR__')) exit;

use PHPMailer\PHPMailer\SMTP;
use PHPMailer\PHPMailer\PHPMailer;

require __DIR__ . "/vendor/autoload.php";

/**
 * Typecho 通知插件
 * 
 * @package     Notifier
 * @author      zengxs
 * @version     0.1.0
 * @dependence  17.11.15
 * @link        https://zengxs.com/posts/typecho-notifier.html
 */
class Notifier_Plugin implements Typecho_Plugin_Interface
{
    /**
     * 激活插件方法
     * 
     * @access public
     * @return string
     * @throws Typecho_Plugin_Exception
     */
    public static function activate()
    {
        Typecho_Plugin::factory('Widget_Feedback')->finishComment = array('Notifier_Plugin', 'commentNotify');
        Typecho_Plugin::factory('Widget_Service')->sendCommentNotification = array('Notifier_Plugin', 'sendCommentNotification');

        // 初始化数据库
        $twig = new Twig\Environment(new Twig\Loader\ArrayLoader([
            'sqlite' => 'ALTER TABLE [{{prefix}}comments] ADD COLUMN [receiveMail] INTEGER(1) DEFAULT 1;',
            'mysql' => 'ALTER TABLE `{{prefix}}comments` ADD COLUMN `receiveMail` TINYINT DEFAULT 1;',
            'pgsql' => 'ALTER TABLE "{{prefix}}comments" ADD COLUMN "receiveMail" SMALLINT DEFAULT 1;',
        ]));

        $db_type = 'sqlite';

        $db = Typecho_Db::get();
        switch (strtolower($db->getAdapterName())) {
            case 'sqlite':
            case 'pdo_sqlite':
                $db_type = 'sqlite';
                break;
            case 'pgsql':
            case 'pdo_pgsql':
                $db_type = 'pgsql';
                break;
            case 'mysql':
            case 'mysqli':
            case 'pdo_mysql':
                $db_type = 'mysql';
                break;
            default:
                return sprintf('不支持的数据库适配器: %s', $db->getAdapterName());
        }

        $sql = $twig->render($db_type, ['prefix' => $db->getPrefix()]);
        if (!array_key_exists('receiveMail', $db->fetchRow($db->select()->from('table.comments'))))
            $db->query($twig->render($db_type, ['prefix' => $db->getPrefix()]));

        return '请设置插件相关配置';
    }

    /**
     * 禁用插件方法
     */
    public static function deactivate()
    { }

    /**
     * 插件配置方法
     */
    public static function config(Typecho_Widget_Helper_Form $form)
    {
        $type = new Typecho_Widget_Helper_Form_Element_Select(
            'NotifyType',
            array(
                'smtp' => 'SMTP',
                'qqmail' => 'QQ邮箱',
                'gmail' => 'GMail',
                'outlook' => 'Outlook',
                'yandex' => 'Yandex.Mail',
                'qqexmail' => '腾讯企业邮箱',
                // 'ses' => 'AWS SES',
                // 'mailgun' => 'Mailgun',
            ),
            0,
            _t('通知类型'),
            _t(file_get_contents(__DIR__ . '/tips.html'))
        );
        $js_element = new Typecho_Widget_Helper_Layout('script', array('type' => 'text/javascript'));
        $js_element->html(file_get_contents(__DIR__ . '/notifier-config.js'));
        $type->container($js_element);
        $form->addInput($type);

        $form->addInput(new Typecho_Widget_Helper_Form_Element_Text('SMTP_host', NULL, '', _t('SMTP 服务器'), _t('邮件服务器域名或 IP，如 QQ 邮箱此处应填写 <i>smtp.qq.com</i>')));
        $form->addInput(new Typecho_Widget_Helper_Form_Element_Text('SMTP_port', NULL, '465', _t('SMTP 端口'), _t('邮件服务器端口, 通常为 25, 465 或 587')));
        $form->addInput(new Typecho_Widget_Helper_Form_Element_Radio(
            'SMTP_type',
            array('plain' => '明文传输', 'ssl' => 'SSL/TLS', 'starttls' => 'STARTTLS'),
            'ssl',
            _t('SMTP 连接类型'),
            _t('25 端口通常使用<b>明文传输</b>类型, 465 端口常为 <b>SSL/TLS</b> (SMTPS), 587 端口常为 <b>STARTTLS</b>。')
        ));
        $form->addInput(new Typecho_Widget_Helper_Form_Element_Radio('SMTP_auth', array(1 => '是', 0 => '否'), 1, _t('SMTP 身份认证'), _t('是否需要启用 SMTP 用户名密码认证')));
        $form->addInput(new Typecho_Widget_Helper_Form_Element_Text('SMTP_user', NULL, '', _t('SMTP 账号')));
        $form->addInput(new Typecho_Widget_Helper_Form_Element_Text('SMTP_pass', NULL, '', _t('SMTP 密码')));
        $form->addInput(new Typecho_Widget_Helper_Form_Element_Text('SMTP_from', NULL, '', _t('SMTP 发件人地址'), _t('可选配置，默认将与 <b>SMTP 账号</b>相同，如果您的 SMTP 账号不是您的邮箱地址，您必须填写此项')));
        $form->addInput(new Typecho_Widget_Helper_Form_Element_Text('SMTP_sender', NULL, 'Typecho Notifier', _t('SMTP 发件人姓名')));

        $form->addInput(new Typecho_Widget_Helper_Form_Element_Text('QQMAIL_user', NULL, '', _t('QQ 邮箱地址'), _t('支持 @qq.com, @foxmail.com 等后缀')));
        $form->addInput(new Typecho_Widget_Helper_Form_Element_Text('QQMAIL_pass', NULL, '', _t('QQ 邮箱密码'), _t('为了保障您的 QQ 账号安全，建议使用 QQ 邮箱 SMTP 专用密码。<br/>进入 <b>QQ 邮箱</b> -> <b>设置</b> -> <b>账户</b> -> <b>开启 IMAP/SMTP 服务</b>即可获取 SMTP 专用密码。')));

        $form->addInput(new Typecho_Widget_Helper_Form_Element_Radio(
            'QQEXMAIL_server',
            array('china' => '国内服务器', 'hw' => '海外服务器'),
            'china',
            _t('腾讯企业邮箱服务器'),
            _t('如果您的服务器在国外，使用腾讯企业邮箱海外服务器发送邮件可能会更顺畅')
        ));
        $form->addInput(new Typecho_Widget_Helper_Form_Element_Text('QQEXMAIL_user', NULL, '', _t('腾讯企业邮箱地址'), _t('请输入您的邮箱地址')));
        $form->addInput(new Typecho_Widget_Helper_Form_Element_Text('QQEXMAIL_pass', NULL, '', _t('腾讯企业邮箱密码'), _t('为了保障您的账号安全，建议使用邮箱 SMTP 专用密码')));

        $form->addInput(new Typecho_Widget_Helper_Form_Element_Text('GMAIL_user', NULL, '', _t('GMail 地址'), _t('注意在国内网络环境下，GMail 服务可能无法访问')));
        $form->addInput(new Typecho_Widget_Helper_Form_Element_Text('GMAIL_pass', NULL, '', _t('GMail 密码'), _t('为了保障您的 Gmail 账号安全，请使用 SMTP 独立密码')));

        $form->addInput(new Typecho_Widget_Helper_Form_Element_Text('OUTLOOK_user', NULL, '', _t('Outlook 地址'), _t('支持 @outlook.com, @hotmail.com 等后缀')));
        $form->addInput(new Typecho_Widget_Helper_Form_Element_Text('OUTLOOK_pass', NULL, '', _t('Outlook 密码'), _t('')));

        $form->addInput(new Typecho_Widget_Helper_Form_Element_Text('YANDEX_user', NULL, '', _t('Yandex.Mail 地址'), _t('@yandex.com 邮箱请填写邮箱地址 @ 前面的字符串，Yandex.Mail 自定义域名邮箱填写完整的邮箱地址。')));
        $form->addInput(new Typecho_Widget_Helper_Form_Element_Text('YANDEX_pass', NULL, '', _t('Yandex.Mail 密码'), _t('')));


        $tpl_hint = _t('使用 <a href="https://twig.symfony.com/">twig</a> 模版语法，支持 <i>post</i>, <i>comment</i> 变量');
        $form->addInput(new Typecho_Widget_Helper_Form_Element_Text('NotifySubjectTemplate', NULL, _t('Typecho: 来自 《{{ post.title }}》 的评论'), _t('通知标题模版'), $tpl_hint));

        $form->addInput(new Typecho_Widget_Helper_Form_Element_Textarea(
            'NotifyTemplate',
            NULL,
            file_get_contents(__DIR__ . '/default-template.twig'),
            _t('通知正文模版'),
            $tpl_hint
        ));
    }

    /**
     * 个人用户的配置方法
     */
    public static function personalConfig(Typecho_Widget_Helper_Form $form)
    { }

    /**
     * 检查配置是否有效
     */
    public static function configCheck(array $settings)
    {
        // TODO
    }

    /**
     * 评论通知
     * 
     * @param $comment
     */
    public static function commentNotify($comment)
    {
        if ($comment instanceof Widget_Feedback) {
            $receiveMail = (isset($_POST['receiveMail']) && 'yes' == $_POST['receiveMail']) ? 1 : 0;
            $db = Typecho_Db::get();
            $db->query($db->update('table.comments')
                ->rows(['receiveMail' => $receiveMail])
                ->where('coid = ?', $comment->coid));
        }

        $options = Helper::options();
        $pluginOptions = $options->plugin('Notifier');
        switch ($pluginOptions->NotifyType) {
            case 'smtp':
            case 'qqmail':
            case 'gmail':
            case 'outlook':
            case 'qqexmail':
            case 'yandex':
                Helper::requestService('sendCommentNotification', $comment->coid);
                break;
            default:
                return;
        }
    }

    /**
     * 发送评论通知
     * 
     * @access  public
     * @param   int $commentId 评论ID
     * @return  void
     */
    public static function sendCommentNotification($commentId)
    {
        $options = Helper::options();
        $pluginOptions = $options->plugin('Notifier');
        $comment = Helper::widgetById('comments', $commentId);

        $mail = new PHPMailer(false);
        $mail->setLanguage('zh_cn');
        $mail->isSMTP();
        $mail->SMTPDebug = SMTP::DEBUG_SERVER;

        switch ($pluginOptions->NotifyType) {
            case 'smtp':
                $mail->Host = $pluginOptions->SMTP_host;
                $mail->Port = $pluginOptions->SMTP_port;
                if ($pluginOptions->SMTP_auth == '0') {
                    $mail->SMTPAuth = false;
                } else {
                    $mail->SMTPAuth = true;
                    $mail->Username = $pluginOptions->SMTP_user;
                    $mail->Password = $pluginOptions->SMTP_pass;
                }
                switch ($pluginOptions->SMTP_type) {
                    case 'plain':
                        $mail->SMTPAutoTLS = true;
                        break;
                    case 'ssl':
                        $mail->SMTPSecure = PHPMailer::ENCRYPTION_SMTPS;
                        break;
                    case 'starttls':
                        $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
                        break;
                }
                $smtp_from = $pluginOptions->SMTP_from;
                if (empty($smtp_from)) $smtp_from = $pluginOptions->SMTP_user;
                $mail->setFrom($smtp_from, $pluginOptions->SMTP_sender);
                break;
            case 'qqmail':
                $mail->Host = 'smtp.qq.com';
                $mail->Port = '465';
                $mail->SMTPAuth = true;
                $mail->SMTPSecure = PHPMailer::ENCRYPTION_SMTPS;
                $mail->Username = $pluginOptions->QQMAIL_user;
                $mail->Password = $pluginOptions->QQMAIL_pass;
                $mail->setFrom($pluginOptions->QQMAIL_user, 'Typecho Notifier');
                break;
            case 'qqexmail':
                switch ($pluginOptions->QQEXMAIL_server) {
                    case 'hw':
                        $mail->Host = 'hwsmtp.exmail.qq.com';
                        break;
                    case 'china':
                    default:
                        $mail->Host =  'smtp.exmail.qq.com';
                        break;
                }
                $mail->Port = '465';
                $mail->SMTPAuth = true;
                $mail->SMTPSecure = PHPMailer::ENCRYPTION_SMTPS;
                $mail->Username = $pluginOptions->QQEXMAIL_user;
                $mail->Password = $pluginOptions->QQEXMAIL_pass;
                $mail->setFrom($pluginOptions->QQEXMAIL_user, 'Typecho Notifier');
                break;
            case 'gmail':
                $mail->Host = 'smtp.gmail.com';
                $mail->Port = '465';
                $mail->SMTPAuth = true;
                $mail->SMTPSecure = PHPMailer::ENCRYPTION_SMTPS;
                $mail->Username = $pluginOptions->GMAIL_user;
                $mail->Password = $pluginOptions->GMAIL_pass;
                $mail->setFrom($pluginOptions->GMAIL_user, 'Typecho Notifier');
                break;
            case 'outlook':
                $mail->Host = 'smtp.office365.com';
                $mail->Port = '587';
                $mail->SMTPAuth = true;
                $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
                $mail->Username = $pluginOptions->OUTLOOK_user;
                $mail->Password = $pluginOptions->OUTLOOK_pass;
                $mail->setFrom($pluginOptions->OUTLOOK_user, 'Typecho Notifier');
                break;
            case 'yandex':
                $mail->Host = 'smtp.yandex.com';
                $mail->Port = '465';
                $mail->SMTPAuth = true;
                $mail->SMTPSecure = PHPMailer::ENCRYPTION_SMTPS;
                $mail->Username = $pluginOptions->YANDEX_user;
                $mail->Password = $pluginOptions->YANDEX_pass;

                $yandex_from = strpos($pluginOptions->YANDEX_user, '@') ? $pluginOptions->YANDEX_user : $pluginOptions->YANDEX_user . '@yandex.com';
                $mail->setFrom($yandex_from, 'Typecho Notifier');
                break;
            default:
                return;
        }

        $post = Helper::widgetById('contents', $comment->cid);

        $twig = new Twig\Environment(new Twig\Loader\ArrayLoader([
            'mail_body' => $pluginOptions->NotifyTemplate,
            'mail_subject' => $pluginOptions->NotifySubjectTemplate,
        ]));

        $mail->CharSet = PHPMailer::CHARSET_UTF8;
        $mail->msgHTML($twig->render('mail_body', ['post' => $post, 'comment' => $comment]));
        $mail->Subject = $twig->render('mail_subject', ['post' => $post, 'comment' => $comment]);

        $mail->addAddress($post->author->mail, $post->author->name);

        $mail->send();
    }
}
