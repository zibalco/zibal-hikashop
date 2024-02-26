<?php

defined('_JEXEC') or die('Restricted access');

class plgHikashoppaymentZibal extends hikashopPaymentPlugin
{
    public $accepted_currencies = [
        'IRR', 'TOM', 'IRT'
    ];
    public $multiple = true;
    public $name = 'zibal';
    public $doc_form = 'zibal';

    public function __construct(&$subject, $config)
    {
        parent::__construct($subject, $config);
    }

    public function onBeforeOrderCreate(&$order, &$do)
    {
        if (parent::onBeforeOrderCreate($order, $do) === true) {
            return true;
        }
        if (empty($this->payment_params->merchant)) {
            $this->app->enqueueMessage('Please check your &quot;Zibal&quot; plugin configuration');
            $do = false;
        }
    }

    public function onAfterOrderConfirm(&$order, &$methods, $method_id)
    {
        parent::onAfterOrderConfirm($order, $methods, $method_id);

        $usergroupsids = "usergroup:" . json_encode(JAccess::getGroupsByUser($this->user->id));/*added by mjt to send more description to zibal logs*/
        if ($this->user) {
            if (is_array($usergroupsids))
                $customdesc = $this->user->name . " آیدی کاربر: " . $this->user->id . " نام کاربری:" . $this->user->username . " آیدی های گروه های کاربری " . json_encode($usergroupsids);
            else
                $customdesc = $this->user->name . " آیدی کاربر: " . $this->user->id . " نام کاربری:" . $this->user->username . " آیدی های گروه های کاربری " . $usergroupsids;
        } else {
            $customdesc = "کاربر میهمان";
        }
        /*added by mjt */
        $callbackUrl = HIKASHOP_LIVE . 'index.php?option=com_hikashop&ctrl=checkout&task=notify&notif_payment=' . $this->name . '&tmpl=component&lang=' . $this->locale . $this->url_itemid . '&orderId=' . $order->order_id;
        $Description = 'سفارش شماره: ' . $order->order_id . "\nبیشتر:" . "$customdesc";
        if (extension_loaded('mbstring'))
            $Description = (strlen($Description) > 490) ? mb_substr($Description, 0, 440) . 'طول اطلاعات زیاد است' : $Description;
        else
            $Description = (strlen($Description) > 490) ? substr($Description, 0, 440) . 'طول اطلاعات زیاد است' : $Description;
        $amount = round($order->cart->full_total->prices[0]->price_value_with_tax, (int)$this->currency->currency_locale['int_frac_digits']);
        if ($this->currency->currency_code== 'IRT' || $this->currency->currency_code== 'TOM')
            $amount= (int)$amount * 10;

        $Description .= "\n";
        $Description .= " محصول: ";
        $Description .= current($order->cart->products)->order_product_code;


        $user = JFactory::getUser();
        $email = $user->get('email', 'guest@gmail.com');

        $parameters = [
            'merchant' => $this->payment_params->merchant,
            'amount' => $amount,
            'description' => $Description,
            'callbackUrl' => $callbackUrl
        ];
//        $username = $user->get('username', 'guest');
//        $parameters['metadata']['mobile']= $username;

        $jsonData = json_encode($parameters);
        $ch = curl_init('https://gateway.zibal.ir/v1/request');
        curl_setopt($ch, CURLOPT_USERAGENT, 'Zibal Rest Api v1');
        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'POST');
        curl_setopt($ch, CURLOPT_POSTFIELDS, $jsonData);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, array(
            'Content-Type: application/json',
            'Content-Length: ' . strlen($jsonData)
        ));
        $result = curl_exec($ch);
        $err = curl_error($ch);
        $result = json_decode($result, true, JSON_PRETTY_PRINT);
        curl_close($ch);

        if ($err) {
            echo "cURL Error #:" . $err;
        } else {
            if (empty($result['errors'])) {
                if ($result['result'] == 100) {
                    header('Location: https://gateway.zibal.ir/start/' . $result["trackId"]);
                }
            } else {
                echo 'Error Code: ' . $result['result'];
                echo 'message: ' . $result['result'];

            }
        }
    }

    public function onPaymentNotification(&$statuses)
    {
        $filter = JFilterInput::getInstance();

        $dbOrder = $this->getOrder($_REQUEST['orderId']);
        $this->loadPaymentParams($dbOrder);
        if (empty($this->payment_params)) {
            return false;
        }
        $this->loadOrderData($dbOrder);
        if (empty($dbOrder)) {
            echo 'Could not load any order for your notification ' . $_REQUEST['orderId'];

            return false;
        }
        $order_id = $dbOrder->order_id;

        $url = HIKASHOP_LIVE . 'administrator/index.php?option=com_hikashop&ctrl=order&task=edit&order_id=' . $order_id;

        if (!empty($this->payment_params->return_url))
            $return_url = $this->payment_params->return_url . '/?order_id=' . $order_id;
        else
            $return_url = HIKASHOP_LIVE . 'index.php?option=com_hikashop&ctrl=order&order_id=' . $order_id;

        if (!empty($this->payment_params->cancel_url))
            $cancel_url = $this->payment_params->cancel_url . '/?order_id=' . $order_id;
        else
            $cancel_url = HIKASHOP_LIVE . 'index.php?option=com_hikashop&ctrl=order&order_id=' . $order_id;

        
        $order_text = "\r\n" . JText::sprintf('NOTIFICATION_OF_ORDER_ON_WEBSITE', $dbOrder->order_number, HIKASHOP_LIVE);
        $order_text .= "\r\n" . str_replace('<br/>', "\r\n", JText::sprintf('ACCESS_ORDER_WITH_LINK', $url));
        if($dbOrder->order_status == $this->payment_params->verified_status)
        return;    //added to remove some bug for order some times have multi history that ended with 
        if (!empty($_GET['trackId'])) {
            $history = new stdClass();
            $history->notified = 0;
            $history->amount = round($dbOrder->order_full_price, (int)$this->currency->currency_locale['int_frac_digits']);
            if ($this->currency->currency_code== 'IRT' || $this->currency->currency_code== 'TOM')
                $history->amount= (int)$history->amount * 10;
            $history->data = ob_get_clean();


            $msg = '';
            $type = 'error';
            $trackId = $_GET['trackId'];
            $data = array("merchant" => $this->payment_params->merchant, "trackId" => $trackId);
            $jsonData = json_encode($data);
            $ch = curl_init('https://gateway.zibal.ir/v1/verify');
            curl_setopt($ch, CURLOPT_USERAGENT, 'Zibal Rest Api v4');
            curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'POST');
            curl_setopt($ch, CURLOPT_POSTFIELDS, $jsonData);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_HTTPHEADER, array(
                'Content-Type: application/json',
                'Content-Length: ' . strlen($jsonData)
            ));

            $result = curl_exec($ch);
            $err = curl_error($ch);
            curl_close($ch);
            $result = json_decode($result, true);

            if ($err) {
                echo "cURL Error #:" . $err;
            } else {
                if ($result['result'] == 100) {
                    $order_status = $this->payment_params->verified_status;
                    $msg = 'پرداخت شما با موفقیت انجام شد.';
                    $type = 'success';
                    JFactory::getApplication()->enqueueMessage($msg, $type);
                    $dest_url = $return_url;
                } else {
                    $order_status = $this->payment_params->invalid_status;
                    $order_text = JText::sprintf('CHECK_DOCUMENTATION', HIKASHOP_HELPURL . 'payment-zibal-error#verify') . "\r\n\r\n" . $order_text;
                    $msg= 'پرداخت شما انجام نشد';
                    JFactory::getApplication()->enqueueMessage($msg, 'error');
                    $dest_url = $cancel_url;
                }
            }

            $config = &hikashop_config();
            if ($config->get('order_confirmed_status', 'confirmed') == $order_status) {
                $history->notified = 1;
            }

            $email = new stdClass();
            $email->subject = JText::sprintf('PAYMENT_NOTIFICATION_FOR_ORDER', 'Zibal', $order_status, $dbOrder->order_number);
            $email->body = str_replace('<br/>', "\r\n", JText::sprintf('PAYMENT_NOTIFICATION_STATUS', 'Zibal', $order_status)) . ' ' . JText::sprintf('ORDER_STATUS_CHANGED', $order_status) . "\r\n\r\n" . $order_text;
            if (!$err && $result['result'] == 100)
            {
                $cartClass = hikashop_get('class.cart');
                $cartClass->cleanCartFromSession();
            }
            $this->modifyOrder($order_id, $order_status, $history, $email);
        } else {
            $order_status = $this->payment_params->invalid_status;
            $email = new stdClass();
            $email->subject = JText::sprintf('NOTIFICATION_REFUSED_FOR_THE_ORDER', 'Zibal') . 'invalid transaction';
            $email->body = JText::sprintf("Hello,\r\n A Zibal notification was refused because it could not be verified by the zibal server (or pay cenceled)") . "\r\n\r\n" . JText::sprintf('CHECK_DOCUMENTATION', HIKASHOP_HELPURL . 'payment-zibal-error#invalidtnx');
            $action = false;
            $this->modifyOrder($order_id, $order_status, null, $email);
            $dest_url = $cancel_url;
        }
//         if (headers_sent()) {
//             die('<script type="text/javascript">window.location.href="' . $dest_url . '";</script>');
//         } else {
//             header('location: ' . $dest_url);
//             die();
//         }
        $app = JFactory::getApplication();
        $app->redirect(JRoute::_($dest_url, false), $msg, $type);
        exit;
    }

    public function getStatusMessage($result)
    {
        $result = (string)$result;
        $resultCode = [
            '102'  => 'merchant یافت نشد',
            '103'  => 'merchant غیرفعال',
            '104'  => 'merchant نامعتبر',
            '201'  => 'قبلا تایید شده',
            '105' => 'amount بایستی بزرگتر از 1,000 ریال باشد',
            '106' => 'callbackUrl نامعتبر می‌باشد. (شروع با http و یا https)',
            '113' => 'amount مبلغ تراکنش از سقف میزان تراکنش بیشتر است.',
            '202' => 'سفارش پرداخت نشده یا ناموفق بوده است',
            '203' => 'trackId نامعتبر می‌باشد',
            '100' => 'عملیات با موفقیت انجام شده است', 
        ];
        if (isset($resultCode[$result])) {
            return $resultCode[$result];
        }

        return 'خطای نامشخص. کد خطا: ' . $result;
    }

    public function onPaymentConfiguration(&$element)
    {
        $subtask = JFactory::getApplication()->input->get('subtask', '');

        parent::onPaymentConfiguration($element);
    }

    public function onPaymentConfigurationSave(&$element)
    {
        return true;
    }

    public function getPaymentDefaultValues(&$element)
    {
        $element->payment_name = 'درگاه پرداخت زیبال';
        $element->payment_description = '';
        $element->payment_images = '';

        $element->payment_params->invalid_status = 'cancelled';
        $element->payment_params->pending_status = 'created';
        $element->payment_params->verified_status = 'confirmed';
    }

    //need to help in programing
    public function mjtTruncate($string, $length = 480, $append = "&hellip;")
    {
        $string = trim($string);

        if (strlen($string) > $length) {
            $string = wordwrap($string, $length);
            $string = explode("\n", $string, 2);
            $string = $string[0] . $append;
        }

        return $string;
    }

}
