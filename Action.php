<?php
class ErcerLink_Action extends Typecho_Widget implements Widget_Interface_Do {
    public function execute(){}

    public function action(){}

    public function link_add() {
        if ($_SERVER['REQUEST_METHOD'] != 'POST') {
            die('迷路了吗😋');
        }

        try {
            $db = Typecho_Db::get();

            $realIp = filter_input(INPUT_SERVER, 'REMOTE_ADDR', FILTER_VALIDATE_IP);

            $postData = [
                'name' => filter_input(INPUT_POST, 'host_name', FILTER_SANITIZE_STRING),
                'url' => filter_input(INPUT_POST, 'host_url', FILTER_SANITIZE_URL),
                'image' => filter_input(INPUT_POST, 'host_png', FILTER_SANITIZE_URL),
                'description' => filter_input(INPUT_POST, 'host_msg', FILTER_SANITIZE_STRING),
                'user' => $realIp,
                'sort' => 'others'
            ];

            // Check if the URL exists in the database
            $query = $db->select('url')->from('table.links')->where('url = ?', $postData['url']);
            $result = $db->fetchRow($query);

            if ($result != NULL) {
                die('已经提交过了噢😘');
            }

            $insert = $db->insert('table.links')->rows($postData);
            $insertId = $db->query($insert);
            die('200');
        } catch (Exception $e) {
            // Handle exceptions here
            die('发生了错误😞');
        }
    }
}