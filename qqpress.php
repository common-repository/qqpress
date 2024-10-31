<?php
/*
Plugin Name: 微博同步工具
Author:  cooiky
Author URI: http://www.hencuo.com/
Plugin URI: http://app.hencuo.com/qqpress
Description: 新文章发布时，将文章标题和URL发布到你的腾讯微博。插件设置页面直接发微博。在日志中提到微博用户时，则通知这些用户。
Version: 1.5
*/

$qq_consumer_key = '4dd4655596bb4b828cda01d6fc8de0d1';
$qq_consumer_secret = '5d1c003f3f281827c2b4f8d859e1469e';

register_activation_hook(__FILE__, 'qqpress_install');
register_deactivation_hook(__FILE__, 'qqpress_remove');
add_action('init', 'qc_init');
add_action("wp_head", "qc_wp_head");
add_action("admin_head", "qc_wp_head");
add_action('admin_menu', 'qc_options_add_page');
add_action('admin_menu', 'qqweibo_option_menu');
add_action('wp_insert_post', 'qqpress_run');
add_option('qqpress_message', '新日志：[title] [permalink]##100', '', 'yes');
define("MB_RETURN_FORMAT", "json");

function qc_init() {
	if (session_id() == "")
		session_start();
	if (isset($_POST["qqpress_send"]) && "1" == isset($_POST["qqpress_send"]) && isset($_POST["qqpress_tweet"])) {
		echo qqpress_publish_2_qq($_POST["qqpress_tweet"]);
		die();
	}
}

//在文章发布页增加是否同步到微博的复选框
function qqweibo_option_menu() {
	if( function_exists('add_meta_box')) {
    	add_meta_box('qqweibo_meta_box', __('腾讯微博同步设置'), 'inner_qqweibo_option_menu', 'post', 'advanced');
	}
}

function inner_qqweibo_option_menu($post) {
    echo '<label for="xx">同步到腾讯微博</label>';
    echo '<input id="xx" name="istoqq" type="checkbox" value="1" checked />';
}

//新日志发布时执行
function qqpress_run($postID) {
	if ($_POST['istoqq']) {
		$post = get_post($postID); //获取日志信息
	
		//如果该日志并未同步到腾讯微博
		if (!qqpress_was_qqed($postID))
			qqpress_db_update_post($postID, $post->post_status); //改变该日志的同步状态为"qqed"

		qqpress_process_posts(); //同步到腾讯微博
	}
}

//创建日志同步状态表
function qqpress_install() {
	global $wpdb;
	$table_name = "qqpress";
	
	qqpress_db_drop_table(); //删除旧的日志同步状态表

   	if( $wpdb->get_var( "SHOW TABLES LIKE '$table_name'" ) != $table_name ) {
		$sql = "CREATE TABLE " . $table_name . " (
	 	 id mediumint(9) NOT NULL,
	 	 status enum('publish', 'draft', 'private', 'static', 'object', 'attachment', 'inherit', 'future', 'qqed') NOT NULL,
	 	 UNIQUE KEY id (id)
		);";

		if (version_compare($wp_version, '2.3', '>='))		
			require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
		else
			require_once(ABSPATH . 'wp-admin/upgrade-functions.php');

		dbDelta($sql);
	}

	add_option("qqpress_db_version", "1.0");
	
	//初始化日志同步状态表
	$sql = "SELECT ID, post_status from " . $wpdb->posts;
	$posts = $wpdb->get_results($sql, OBJECT);
	foreach ($posts as $post) {
		if ($post->post_status == "publish")
			qqpress_db_update_post($post->ID, "qqed"); //日志状态为publish的，同步状态为已同步
		else
			qqpress_db_update_post($post->ID, $post->post_status);
	}
}

function qqpress_remove() {
	//删除 wp_options 表中的对应记录
	delete_option('qqpress_message');
	delete_option('qqpress_db_version');
	delete_option('qq_oauth_token');
	delete_option('qq_oauth_token_secret');
	qqpress_db_drop_table(); //删除日志同步状态表
}

//如果已经存在日志同步状态表qqpress，则删除
function qqpress_db_drop_table() {
	global $wpdb;
	$table_name = "qqpress";

   	if( $wpdb->get_var("SHOW TABLES LIKE '$table_name'") != $table_name ) {
		$sql = "DROP TABLE " . $table_name;

		if (version_compare($wp_version, '2.3', '>='))		
			require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
		else
			require_once(ABSPATH . 'wp-admin/upgrade-functions.php');

		dbDelta($sql);
	}
}

//新增或更新日志的同步状态
function qqpress_db_update_post($postID, $status) {
	global $wpdb;
	$table_name = "qqpress";
	
	qqpress_db_delete_post($postID); //如果同步状态表中已经存在该日志，删除
	
	//插入该日志对应的同步状态
	$query = "INSERT INTO " . $table_name . " (id, status) " . "VALUES (" . $wpdb->escape($postID) . ", '" . $wpdb->escape($status) . "')";
	return $wpdb->query($query);
}

//判断该日志是否已经同步
function qqpress_was_qqed($postID) {
	global $wpdb;
	$table_name = "qqpress";

	$query = "SELECT id, status from " . $table_name . " WHERE id = " . $wpdb->escape($postID);

	return $wpdb->get_var($query, 1) == 'qqed'; 
}

//删除同步状态表中的该日志记录
function qqpress_db_delete_post($postID) {
	global $wpdb;
	$table_name = "qqpress";

	$query = "DELETE FROM " . $table_name . " WHERE id like " . $wpdb->escape($postID);

	return $wpdb->query($query);
}

//将最新文章同步到腾讯微博，并更新该日志的同步状态为已同步
function qqpress_process_posts() {
	global $wpdb;
	$table_name = "qqpress";

	$query = "SELECT * FROM " . $table_name;

	$tp_rows = $wpdb->get_results($query, ARRAY_A); //获取状态表中的所有日志

	//对状态表中的所有日志
	foreach($tp_rows as $row) {
		$tp_wp_status = $row["status"];
		//如果该日志状态为published，则同步到腾讯微博，并将状态更新为已同步
		if ($tp_wp_status == "publish"){
			qqpress_publish($row["id"]);
			qqpress_db_update_post($row["id"], 'qqed');
		}
	}
}

//将日志同步到腾讯微博
function qqpress_publish($postID) {	
	$post = get_post($postID);
	
	//再次确认该日志状态为publish
	if ($post->post_status == "publish") {
		$message = qqpress_get_message($post, $postID); //格式化同步内容
		qqpress_publish_2_qq($message);
		
		//如果日志中有提到微博中用户，则发通知
		preg_match_all("/@([A-Za-z]{1}[\w|-|_]*)[\s+|，|。|？|,|.|?]/", $post->post_content, $matches);
		if (2 == count($matches) && 0 < count($matches[1])) {
			$oauth_token = get_option("qq_oauth_token");
			$oauth_token_secret = get_option("qq_oauth_token_secret");
			
			if(!$oauth_token || !$oauth_token_secret)
				return;

			if(!class_exists('MBOpenTOAuth'))
				include dirname(__FILE__).'/opent.php';
			
			global $qq_consumer_key, $qq_consumer_secret;
			$to = new MBOpenTOAuth($qq_consumer_key, $qq_consumer_secret, $oauth_token, $oauth_token_secret);

			$params = array('format' => 'json', 'name' => "");
			$url = "http://open.t.qq.com/api/user/other_info?f=1";
			$users = array();
			foreach ($matches[1] as $username) {
				$params["name"] = $username;
				try {
					$result = $to->get($url, $params);
					if (false === array_search($username, $users))
						array_push($users, $result["data"]["name"]);
				} catch (Exception $d) {}
			}
			if (0 < count($users)) {
				$message = "我在新日志《" . $post->post_title . "》中提到了@" . implode(" @", $users) ." ". get_permalink($postID);
				qqpress_publish_2_qq($message);
			}
		}
	}
}

//将日志转换为自定义的同步格式
function qqpress_get_message($post, $postID) {
	$message = get_option('qqpress_message');
	list($proto, $length) = explode("##", $message);
	$length = $length == 0 ? 100 : $length;

	$proto = str_replace("[title]", $post->post_title, $proto);
	$proto = str_replace("[permalink]", get_permalink($postID), $proto);
	$proto = str_replace("[link]", get_option('home')."?p=".$postID, $proto);

	if (0 == $length)
		return $proto;
	else {
		$length = $length > 110 ? 100 : $length;
		$content = getstr($post->post_content, $length, $encoding  = 'utf-8'); //文章内容预览
		return $proto."  ".$content."...";
	}
}

// 关闭腾讯微博OAUTH授权返回窗口
function qc_wp_head() {
    if(isset($_GET['oauth_token'])) {
		qc_confirm(); // 获取授权后的oauth_token和oauth_token_secret
		echo '<script type="text/javascript">window.opener.qc_reload("");window.close();</script>';
    }
}

//插件设置页面
function qc_options_add_page() {
	add_options_page('腾讯微博同步', '腾讯微博同步', 'manage_options', 'qc_options', 'qc_options_do_page');
}

function qc_options_do_page() {
	//如果已经存在oauth_token和oauth_token_secret，则不显示授权按钮
	if (get_option("qq_oauth_token") != false && get_option("qq_oauth_token_secret") != false) {
?>
		<div class="wrap"><div id="icon-options-general" class="icon32"><br /></div>
		<h2>腾讯微博同步</h2>
		<div class="updated fade"><p>已成功授权</p></div>
		<p>取消授权请访问：<a href="http://open.t.qq.com/apps/appslist.php" target="_blank">http://open.t.qq.com/apps/appslist.php</a></p>
		</div>
<?php
		qqpress_options_page();
		return;
	}
	$qc_url = WP_PLUGIN_URL.'/'.dirname(plugin_basename (__FILE__));
	
	global $qq_consumer_key, $qq_consumer_secret;
?>
	<script type="text/javascript">
    function qc_reload(){
       var url = location.href;
       var temp = url.split("#");
       url = temp[0];
       url += "#qc_button";
       location.href = url;
       location.reload();
    }
    </script>
	<div class="wrap">
		<h2>腾讯微博同步授权</h2>
        <style type="text/css">.qc_button img{border:none;}</style>
		<p id="qc_connect" class="qc_button">
		<img onclick='window.open("<?php echo $qc_url; ?>/qq-start.php","","width=800,height=600,left=150,top=100,qcrollbar=no,resize=no");return false;' src="<?php echo $qc_url; ?>/qq_button.png" alt="使用腾讯微博登陆" style="cursor: pointer; margin-right: 20px;" />
		</p>
	</div>
	<?php
}

//自定义内容
function qqpress_options_page() {
	global $wpdb;
	$table_name = "qqpress";
	$submitFieldID = 'qqpress_submit_hidden';
	
	if ($_POST[$submitFieldID] == 'Y') {
		$message = $_POST['qqpress_form_message']."##".$_POST['qqpress_form_content'];
		update_option('qqpress_message', $message);
		echo '<div class="updated"><p><strong>更新成功</strong></p></div>';
	}

	$qc_url = WP_PLUGIN_URL.'/'.dirname(plugin_basename (__FILE__));
?>
	<script type="text/javascript">
	/*<![CDATA[*/
	jQuery(function() {
		jQuery("#qqpress_tweet").click(function() {
			var tweet = jQuery.trim(jQuery("#qqpress_tweet_content").val());
			if ("" != tweet) {
				jQuery.ajax({
					type : "POST",
					beforeSend : disable_button,
					dataType : "text",
					url : "<?php echo str_replace('%7E', '~', $_SERVER['REQUEST_URI']); ?>",
					data : "qqpress_tweet=" + tweet + "&qqpress_send=1&timestamp=" + (new Date().getTime()),
					success : show_message
				});
			} else {
				alert("广播内容不能为空！");
			}
		});
	});

	function checkForm() {
		var length = jQuery.trim(jQuery("#qqpress_form_content").val());
		if (!/^[0-9]{1,3}$/.test(length)) {
			alert("长度别太长哦");
			return false;
		}
		return true;
	}
	
	function disable_button() {
		jQuery("#qqpress_tweet").attr("disabled", "disabled");
	}
	
	function show_message(data) {
		jQuery("#qqpress_result").html("发布成功。<a href='http://t.qq.com/p/t/"+data+"' target='_blank'>查看</a>").css("color", "red");
		jQuery("#qqpress_tweet").attr("disabled", "");
	}
	/*]]>*/
	</script>
	<div class="wrap">
		<div class="tool-box">
			<h3>发微博</h3>
			<textarea id="qqpress_tweet_content" name="qqpress_tweet_content" rows="2" cols="80" />#半醒WP微博同步#</textarea><br />
			<input type="button" id="qqpress_tweet" name="qqpress_tweet_button" value=" 广 播 " class="button" /><span id="qqpress_result"></span><br /><br />
			<?php
				$data = get_option('qqpress_message');
				list($message, $length) = explode("##", $data);
				$length = $length!="" ? $length : 0;
			?>
			<h3>腾讯微博同步格式</h3>
			<p><form name="qqpress_form" method="post" action="<?php echo str_replace('%7E', '~', $_SERVER['REQUEST_URI']); ?>" onsubmit="return checkForm()">
				<input type="hidden" name="qqpress_submit_hidden" value="Y" />
				<label for="qqpress_form_message">同步内容格式：</label>
				<input type="text" size="64" class="regular-text" id="qqpress_form_message" name="qqpress_form_message" value="<?php echo $message; ?>" /><br />
				<label for="qqpress_form_content">文章预览长度：</label>
				<input type="text" size="64" maxlength="3" class="regular-text" id="qqpress_form_content" name="qqpress_form_content" value="<?php echo $length; ?>" /><br />
				<input type="submit" name="Submit" class="button" value="保存修改" />
			</form></p>
			<p>同步内容支持以下格式：
			<ul>
				<li><strong>[title]</strong>：日志标题</li>
				<li><strong>[permalink]</strong>：自定义的日志永久链接<em>(<a href="http://www.hencuo.com/archives/119" target="_blank">http://www.hencuo.com/archives/119</a>)</em></li>
				<li><strong>[link]</strong>：默认的日志链接<em>(<a href="http://www.hencuo.com/index.php?p=119" target="_blank">http://www.hencuo.com/index.php?p=119</a>)</em></li>
			</ul>
			</p>
		</div>
	</div>
	<?php
}

//获得腾讯微博授权用户的oauth_token和oauth_token_secret
function qc_confirm() {
    global $qq_consumer_key, $qq_consumer_secret;
	
	if(!class_exists('MBOpenTOAuth'))
		include dirname(__FILE__).'/opent.php';
	
	$to = new MBOpenTOAuth($qq_consumer_key, $qq_consumer_secret, $_GET['oauth_token'], $_SESSION['qq_oauth_token_secret']);
	
	$_SESSION['qq_oauth_token_secret'] = false;
	
	$tok = $to->getAccessToken($_REQUEST['oauth_verifier']);
	
	update_option("qq_oauth_token", $tok['oauth_token']);
	update_option("qq_oauth_token_secret", $tok['oauth_token_secret']);
}

//同步到腾讯微博
function qqpress_publish_2_qq($message) {
	$oauth_token = get_option("qq_oauth_token");
	$oauth_token_secret = get_option("qq_oauth_token_secret");
	
	if(!$oauth_token || !$oauth_token_secret)
		return;

	if(!class_exists('MBOpenTOAuth'))
		include dirname(__FILE__).'/opent.php';

	global $qq_consumer_key, $qq_consumer_secret;
	$to = new MBOpenTOAuth($qq_consumer_key, $qq_consumer_secret, $oauth_token, $oauth_token_secret);

	$params = array('format' => 'json', 'content' => $message, 'clientip' => my_get_ip(), 'jing' => '', 'wei' => '');

	$resp = $to->post('http://open.t.qq.com/api/t/add?f=1', $params);

	return $resp["data"]["id"];
}

//获取IP
function my_get_ip() {
	$onlineip = '127.0.0.1';
	
	if(getenv('HTTP_CLIENT_IP'))
		$onlineip = getenv('HTTP_CLIENT_IP');
	elseif(getenv('HTTP_X_FORWARDED_FOR'))
		$onlineip = getenv('HTTP_X_FORWARDED_FOR');
	elseif(getenv('REMOTE_ADDR'))
		$onlineip = getenv('REMOTE_ADDR');
	else
		$onlineip = $HTTP_SERVER_VARS['REMOTE_ADDR'];

	return $onlineip;
}

//汉字截断
function getstr($string, $length, $encoding  = 'utf-8') {
    $string = strip_tags(trim($string));
    
    if ($length && strlen($string) > $length) {
        //截断字符   
        $wordscut = '';   
        if (strtolower($encoding) == 'utf-8') {
            //utf8编码   
            $n = 0;
            $tn = 0;
            $noc = 0;
            while ($n < strlen($string)) {
                $t = ord($string[$n]);
                if ($t == 9 || $t == 10 || (32 <= $t && $t <= 126)) {
                    $tn = 1;
                    $n++;
                    $noc++;
                } elseif (194 <= $t && $t <= 223) {
                    $tn = 2;
                    $n += 2;
                    $noc += 2;
                } elseif (224 <= $t && $t < 239) {
                    $tn = 3;
                    $n += 3;
                    $noc += 2;
                } elseif (240 <= $t && $t <= 247) {
                    $tn = 4;
                    $n += 4;
                    $noc += 2;
                } elseif (248 <= $t && $t <= 251) {
                    $tn = 5;
                    $n += 5;
                    $noc += 2;
                } elseif ($t == 252 || $t == 253) {
                    $tn = 6;
                    $n += 6;
                    $noc += 2;
                } else
                    $n++;
				
                if ($noc >= $length)
                    break;
            }

            if ($noc > $length)
                $n -= $tn;
			
            $wordscut = substr($string, 0, $n);
        } else {
            for($i = 0; $i < $length - 1; $i++) {
                if (ord($string[$i]) > 127) {
                    $wordscut .= $string[$i].$string[$i + 1];
                    $i++;
                } else
                    $wordscut .= $string[$i];
            }
        }
        $string = $wordscut;
    }
    return trim($string);
}