<?php
class ErcerLink_Action extends Typecho_Widget implements Widget_Interface_Do {
    public function execute(){}

    public function action(){}

    public function link_add() {
        if ($_SERVER['REQUEST_METHOD'] != 'POST') {
            die('迷路了吗😋');
        }

        $db = Typecho_Db::get();

        $realIp = $_SERVER['HTTP_CLIENT_IP'] ?? $_SERVER['HTTP_X_FORWARDED_FOR'] ?? $_SERVER['REMOTE_ADDR'];

        $fields = [
            'name' => filter_input(INPUT_POST, 'host_name', FILTER_SANITIZE_STRING) ?? filter_input(INPUT_POST, 'host_name_page', FILTER_SANITIZE_STRING),
            'url' => filter_input(INPUT_POST, 'host_url', FILTER_SANITIZE_URL) ?? filter_input(INPUT_POST, 'host_url_page', FILTER_SANITIZE_URL),
            'image' => filter_input(INPUT_POST, 'host_png', FILTER_SANITIZE_URL) ?? filter_input(INPUT_POST, 'host_png_page', FILTER_SANITIZE_URL),
            'description' => filter_input(INPUT_POST, 'host_msg', FILTER_SANITIZE_STRING) ?? filter_input(INPUT_POST, 'host_msg_page', FILTER_SANITIZE_STRING),
            'user' => $realIp,
            'sort' => 'others'
        ];
        
        $query = $db->select('url')->from('table.links')->where('url = ?',$fields['url']);
        $result = $db->fetchRow($query);
        
        if ($result != NULL) {
            die('已经提交过了噢😘');
        }
        
        $insert = $db->insert('table.links')->rows($fields);
        $insertId = $db->query($insert);
        die('200');
    }
}