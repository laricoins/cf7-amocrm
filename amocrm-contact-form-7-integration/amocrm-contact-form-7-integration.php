<?php
/*
Plugin Name: CF7 to amoCRM Integration
Description: Integrates Contact Form 7 with amoCRM.
Version: 1.0
Author: Your Name
*/
if (!defined('ABSPATH')) {
    exit; // Exit if accessed directly.
    
}
// Add settings page
add_action('admin_menu', 'cf7_amocrm_add_admin_menu');
add_action('admin_init', 'cf7_amocrm_settings_init');
function cf7_amocrm_add_admin_menu() {
    add_options_page('CF7 amoCRM Integration', 'CF7 amoCRM Integration', 'manage_options', 'cf7_amocrm', 'cf7_amocrm_options_page');
}
function cf7_amocrm_settings_init() {
    register_setting('cf7_amocrm', 'cf7_amocrm_settings');
    add_settings_section('cf7_amocrm_section', __('API Settings', 'cf7_amocrm'), 'cf7_amocrm_settings_section_callback', 'cf7_amocrm');
    add_settings_field('cf7_amocrm_apikey', __('API Key', 'cf7_amocrm'), 'cf7_amocrm_apikey_render', 'cf7_amocrm', 'cf7_amocrm_section');
    add_settings_field('cf7_amocrm_subdomain', __('Subdomain', 'cf7_amocrm'), 'cf7_amocrm_subdomain_render', 'cf7_amocrm', 'cf7_amocrm_section');
}
function cf7_amocrm_apikey_render() {
    $options = get_option('cf7_amocrm_settings');
?>
   <!-- Выводим HTML для textarea ввода API ключа -->
    <textarea name='cf7_amocrm_settings[cf7_amocrm_apikey]' rows='5' cols='80'><?php echo esc_textarea($options['cf7_amocrm_apikey']); ?></textarea>

    <?php
}
function cf7_amocrm_subdomain_render() {
    $options = get_option('cf7_amocrm_settings');
?>
    <input type='text' name='cf7_amocrm_settings[cf7_amocrm_subdomain]' value='<?php echo $options['cf7_amocrm_subdomain']; ?>'>
    <?php
}
function cf7_amocrm_settings_section_callback() {
    echo __('Enter your amoCRM API settings below.', 'cf7_amocrm');
}
function cf7_amocrm_options_page() {
?>
    <form action='options.php' method='post'>
        <h2>CF7 amoCRM Integration</h2>
        <?php
    settings_fields('cf7_amocrm');
    do_settings_sections('cf7_amocrm');
    submit_button();
?>
    </form>
    <?php
}
// Handle CF7 form submission
add_action('wpcf7_mail_sent', 'cf7_amocrm_handle_submission');
function cf7_amocrm_handle_submission($contact_form) {
    $submission = WPCF7_Submission::get_instance();
    if ($submission) {
        $posted_data = $submission->get_posted_data();
        $your_name = sanitize_text_field($posted_data['your-name']);
        $your_phone = sanitize_text_field($posted_data['your-phone']);
        $options = get_option('cf7_amocrm_settings');
        $apikey = $options['cf7_amocrm_apikey'];
        $subdomain = $options['cf7_amocrm_subdomain'];
        $url = get_site_url();
        $url= explode('//',$url)[1];
         $form_name = 'From '.$url . ' CF7# '.$contact_form->title();
        
        if (!$your_phone or !$your_name){
            return;
        }
        if ($apikey && $subdomain) {
            $existing_contact = cf7_amocrm_check_existing_contact($your_phone, $apikey, $subdomain);
            if ($existing_contact) {
                cf7_amocrm_create_contact($your_name, $your_phone, $apikey, $subdomain);
            }
            
            
           $responsible_user =  amocrm_get_user_id_by_phone($your_phone, $apikey, $subdomain);
             amocrm_leads_add($responsible_user,$posted_data, $form_name,$apikey, $subdomain);
            
        }
    }
}
function cf7_amocrm_check_existing_contact($phone, $apikey, $subdomain) // если можно вставить то true
{
    $link = 'https://' . $subdomain . '.amocrm.ru/api/v4/contacts?query=' . urlencode($phone); //Формируем URL для запроса
    
    /** Получаем access_token из вашего хранилища */
    /** Формируем заголовки */
    $headers = ['Authorization: Bearer ' . $apikey];
    $curl = curl_init(); //Сохраняем дескриптор сеанса cURL
    
    /** Устанавливаем необходимые опции для сеанса cURL  */
    curl_setopt($curl, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($curl, CURLOPT_USERAGENT, 'amoCRM-oAuth-client/1.0');
    curl_setopt($curl, CURLOPT_URL, $link);
    curl_setopt($curl, CURLOPT_HTTPHEADER, $headers);
    curl_setopt($curl, CURLOPT_HEADER, false);
    curl_setopt($curl, CURLOPT_SSL_VERIFYPEER, 1);
    curl_setopt($curl, CURLOPT_SSL_VERIFYHOST, 2);
    $data = curl_exec($curl); //Инициируем запрос к API и сохраняем ответ в переменную
    $code = curl_getinfo($curl, CURLINFO_HTTP_CODE);
    curl_close($curl);
    /** Теперь мы можем обработать ответ, полученный от сервера. Это пример. Вы можете обработать данные своим способом. */
    $code = (int)$code;
    $errors = [400 => 'Bad request', 401 => 'Unauthorized', 403 => 'Forbidden', 404 => 'Not found', 500 => 'Internal server error', 502 => 'Bad gateway', 503 => 'Service unavailable', ];
    try {
        /** Если код ответа не успешный - возвращаем сообщение об ошибке  */
        if ($code < 200 || $code > 204) {
            return false;
            throw new Exception(isset($errors[$code]) ? $errors[$code] : 'Undefined error', $code);
        }
        $data = json_decode($data, true);
        if (!$data) {
            return true;
        }
        foreach ($data["_embedded"]["contacts"] as $contact) {
            foreach ($contact["custom_fields_values"] as $field) {
                if ($field["field_code"] === "PHONE") {
                    foreach ($field["values"] as $value) {
                        if ($value["value"] === $phone) {
                            return false;
                        }
                    }
                }
            }
        }
        return true;
    }
    catch(\Exception $e) {
        return false;
        die('Ошибка: ' . $e->getMessage() . PHP_EOL . 'Код ошибки: ' . $e->getCode());
    }
}
function cf7_amocrm_create_contact($name, $phone, $apikey, $subdomain) {
    $url = "https://{$subdomain}.amocrm.ru/api/v4/contacts";
	
$body =json_encode([ (object)['name' => $name, 'first_name'=>$name,
                  'custom_fields_values' => [(object) ['field_code' => 'PHONE', 'values' => [(object) ['value' => $phone,'enum_code'=>'WORK' ] ] ]]
    				  ] ]);
	

    $response = wp_remote_post($url, array('headers' => array('Authorization' => 'Bearer ' . $apikey, 'Content-Type' => 'application/json',), 'body' => $body,));

    if (is_wp_error($response)) {
        return false;
    }
    return true;
}


function amocrm_get_user_id_by_phone($phone, $apikey, $subdomain) { // возвращает responsible_user_id  ай ди юзера
    $link = 'https://' . $subdomain . '.amocrm.ru/api/v4/contacts?query=' . urlencode($phone); //Формируем URL для запроса
    
    /** Получаем access_token из вашего хранилища */
    /** Формируем заголовки */
    $headers = ['Authorization: Bearer ' . $apikey];
    $curl = curl_init(); //Сохраняем дескриптор сеанса cURL
    
    /** Устанавливаем необходимые опции для сеанса cURL  */
    curl_setopt($curl, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($curl, CURLOPT_USERAGENT, 'amoCRM-oAuth-client/1.0');
    curl_setopt($curl, CURLOPT_URL, $link);
    curl_setopt($curl, CURLOPT_HTTPHEADER, $headers);
    curl_setopt($curl, CURLOPT_HEADER, false);
    curl_setopt($curl, CURLOPT_SSL_VERIFYPEER, 1);
    curl_setopt($curl, CURLOPT_SSL_VERIFYHOST, 2);
    $data = curl_exec($curl); //Инициируем запрос к API и сохраняем ответ в переменную
    $code = curl_getinfo($curl, CURLINFO_HTTP_CODE);
    curl_close($curl);
    /** Теперь мы можем обработать ответ, полученный от сервера. Это пример. Вы можете обработать данные своим способом. */
    $code = (int)$code;
    $errors = [400 => 'Bad request', 401 => 'Unauthorized', 403 => 'Forbidden', 404 => 'Not found', 500 => 'Internal server error', 502 => 'Bad gateway', 503 => 'Service unavailable', ];
    try {
        /** Если код ответа не успешный - возвращаем сообщение об ошибке  */
        if ($code < 200 || $code > 204) {
            return false;
            throw new Exception(isset($errors[$code]) ? $errors[$code] : 'Undefined error', $code);
        }
        $data = json_decode($data, true);
        if (!$data) {
            return true;
        }
        // print_r($data);
        foreach ($data["_embedded"]["contacts"] as $contact) {
            foreach ($contact["custom_fields_values"] as $field) {
                if ($field["field_code"] === "PHONE") {
                    foreach ($field["values"] as $value) {
                        if ($value["value"] === $phone) {
                            return $contact;
                        }
                    }
                }
            }
        }
        return true;
    }
    catch(\Exception $e) {
        return false;
        die('Ошибка: ' . $e->getMessage() . PHP_EOL . 'Код ошибки: ' . $e->getCode());
    }
}



function amocrm_leads_add($responsible_user,$posted_data, $form_name,$apikey, $subdomain) {
    $TRAF_SRC = $posted_data['utm_source']; //  TRAF_SRC field_id  564813
    $TRAF_TYPE = $posted_data['utm_medium'];; // TRAF_TYPE field_id  564815
    $ADV_CAMP = $posted_data['utm_campaign'];; // ADV_CAMP field_id  564817
    $KEYWORD = $posted_data['utm_keyword'];; // KEYWORD field_id  564819
    $TRAF_CONT = $posted_data['utm_content'];; //  TRAF_CONTfield_id  564821
	
	
	
    $custom_fields_values = [(object)['field_id' => 286661, 'values' => [(object)['value' => 'Gala']]], (object)['field_id' => 564813, 'values' => [(object)['value' => $TRAF_SRC]]], (object)['field_id' => 564815, 'values' => [(object)['value' => $TRAF_TYPE]]], (object)['field_id' => 564817, 'values' => [(object)['value' => $ADV_CAMP]]], (object)['field_id' => 564819, 'values' => [(object)['value' => $KEYWORD]]], (object)['field_id' => 564821, 'values' => [(object)['value' => $TRAF_CONT]]], ];
    /*
    TRAF_SRC   [hidden utm_source default:get]
    TRAF_TYPE  [hidden utm_medium default:get]
    ADV_CAMP   [hidden utm_campaign default:get]
    KEYWORD   [hidden utm_keyword default:get]
    TRAF_CONT [hidden utm_content default:get]
    */
    $responsible_user_id = $responsible_user['responsible_user_id'];
    
    $tags_to_add = get_site_url();
$tags_to_add = explode('//',$tags_to_add)[1];
$tags_to_add = explode('.',$tags_to_add)[0];
    
    $body = [(object)['name' => $form_name, 'status_id' => 64393690, 'pipeline_id' => 4960297, 'responsible_user_id' => $responsible_user_id, 'created_by' => $responsible_user_id, 'updated_by' => $responsible_user_id, "_embedded" => (object)["contacts" => [(object)['id' => $responsible_user['id']]]], 'custom_fields_values' => $custom_fields_values, 'tags_to_add' => [(object)["name" => $tags_to_add]]]];
    $body = json_encode($body);
  //  print_r($body);
    //  return false;
	

    $url = "https://{$subdomain}.amocrm.ru/api/v4/leads";
    $response = wp_remote_post($url, array('headers' => array('Authorization' => 'Bearer ' . $apikey, 'Content-Type' => 'application/json',), 'body' => $body,));
    //     return false;
    $response = json_decode(wp_remote_retrieve_body($response));
    if (is_wp_error($response)) {
        return false;
    }
    $leads = (array)$response->_embedded;
    $leads = $leads['leads'][0];
    $leads == (array)$leads;
   // print_r($leads);
    $id = $leads->id;
	
 if (!$id){
     return false;
 }
	

    $url = "https://{$subdomain}.amocrm.ru/api/v4/leads/" . $id . "/notes";
	 $body = [(object)['note_type'=>"common",'created_by' => $responsible_user_id,'responsible_user_id' => $responsible_user_id, "params" =>(object)[ "text"=> print_r($posted_data,true)] ]];
  //  print_r($url);
	 $body = json_encode($body);
	  $response = wp_remote_post($url, array('headers' => array('Authorization' => 'Bearer ' . $apikey, 'Content-Type' => 'application/json',), 'body' => $body,));
//  print_r($response);
}