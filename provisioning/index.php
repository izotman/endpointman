<?PHP

require('/etc/freepbx.conf');
require('/var/www/html/admin/modules/endpointman/includes/functions.inc');
require('/var/www/html/admin/modules/endpointman/includes/timezone.php');
define("PHONE_MODULES_DIR", "/var/www/html/admin/modules/_ep_phone_modules/");
require(PHONE_MODULES_DIR . 'endpoint/base.php');

$endpoint = new endpointmanager();

if ((!isset($endpoint->global_cfg['server_type'])) OR ($endpoint->global_cfg['server_type'] != 'http')) {
    header('HTTP/1.1 403 Forbidden');
    die();
}

if ((isset($_SERVER["PATH_INFO"])) && ($_SERVER["PATH_INFO"] != '/') && (!empty($_SERVER["PATH_INFO"]))) {
    $requested_file = substr($_SERVER["PATH_INFO"], 1);
} elseif (isset($_REQUEST['request'])) {
    $requested_file = $_REQUEST['request'];
}

$path_parts = explode(".", $requested_file);
$path_parts2 = explode("_", $path_parts[0]);

$mac = $path_parts2[0];


$webpath = 'http://'.$_SERVER["HTTP_HOST"].dirname($_SERVER["PHP_SELF"])."/";

$tmp_requested_file = str_replace('spa', '', $requested_file);

if (preg_match('/[0-9a-f]{12}/i', $tmp_requested_file, $matches)) {
    if (preg_match('/0000000/', $requested_file)) {
        $data = Provisioner_Globals::dynamic_global_files($requested_file,$webpath);
        if ($data === FALSE) {
            header("HTTP/1.0 404 Not Found");
        } else {
            echo $data;
        }
    } else {
        $mac_address = $matches[0];
    }
} else {
    $data = Provisioner_Globals::dynamic_global_files($requested_file,$webpath);
    if ($data === FALSE) {
        header("HTTP/1.0 404 Not Found");
    } else {
        echo $data;
    }
}


if (isset($mac_address)) {
    $sql = 'SELECT id FROM `endpointman_mac_list` WHERE `mac` LIKE CONVERT(_utf8 \'%' . $mac_address . '%\' USING latin1) COLLATE latin1_swedish_ci';

    $mac_id = $endpoint->db->getOne($sql);
    $phone_info = $endpoint->get_phone_info($mac_id);

    if (file_exists(PHONE_MODULES_DIR . 'setup.php')) {
        if (!class_exists('ProvisionerConfig')) {
            require(PHONE_MODULES_DIR . 'setup.php');
        }


        //Load Provisioner
        $class = "endpoint_" . $phone_info['directory'] . "_" . $phone_info['cfg_dir'] . '_phone';
        $base_class = "endpoint_" . $phone_info['directory'] . '_base';
        $master_class = "endpoint_base";

        if (!class_exists($master_class)) {
            ProvisionerConfig::endpointsAutoload($master_class);
        }
        if (!class_exists($base_class)) {
            ProvisionerConfig::endpointsAutoload($base_class);
        }
        if (!class_exists($class)) {
            ProvisionerConfig::endpointsAutoload($class);
        }
        //end quick fix



        if (class_exists($class)) {
            $provisioner_libary = new $class();
        } else {
            header("HTTP/1.0 500 Internal Server Error");
            die();
        }
    } else {
        header("HTTP/1.0 500 Internal Server Error");
        die();
    }


    //Determine if global settings have been overridden
    $settings = '';
    if ($phone_info['template_id'] > 0) {
        if (isset($phone_info['template_data_info']['global_settings_override'])) {
            $settings = unserialize($phone_info['template_data_info']['global_settings_override']);
        } else {
            $settings['srvip'] = $endpoint->global_cfg['srvip'];
            $settings['ntp'] = $endpoint->global_cfg['ntp'];
            $settings['config_location'] = $endpoint->global_cfg['config_location'];
            $settings['tz'] = $endpoint->global_cfg['tz'];
        }
    } else {
        if (isset($phone_info['global_settings_override'])) {
            $settings = unserialize($phone_info['global_settings_override']);
        } else {
            $settings['srvip'] = $endpoint->global_cfg['srvip'];
            $settings['ntp'] = $endpoint->global_cfg['ntp'];
            $settings['config_location'] = $endpoint->global_cfg['config_location'];
            $settings['tz'] = $endpoint->global_cfg['tz'];
        }
    }

    //Tell the system who we are and were to find the data.
    $provisioner_libary->root_dir = PHONE_MODULES_PATH;
    $provisioner_libary->engine = 'asterisk';
    $provisioner_libary->engine_location = $endpoint->global_cfg['asterisk_location'];
    $provisioner_libary->system = 'unix';

    //have to because of versions less than php5.3
    $provisioner_libary->brand_name = $phone_info['directory'];
    $provisioner_libary->family_line = $phone_info['cfg_dir'];

    //Mac Address
    $provisioner_libary->mac = $phone_info['mac'];

    //Phone Model (Please reference family_data.xml in the family directory for a list of recognized models)
    //This has to match word for word. I really need to fix this....
    $provisioner_libary->model = $phone_info['model'];

    //Timezone
    $http_provisioner->DateTimeZone = new DateTimeZone($settings['tz']);


    //Network Time Server
    $provisioner_libary->ntp = $settings['ntp'];

    //Server IP
    $provisioner_libary->server[1]['ip'] = $settings['srvip'];
    $provisioner_libary->server[1]['port'] = 5060;

    $temp = "";
    $template_data = unserialize($phone_info['template_data']);
    $global_user_cfg_data = unserialize($phone_info['global_user_cfg_data']);
    if ($phone_info['template_id'] > 0) {
        $global_custom_cfg_data = unserialize($phone_info['template_data_info']['global_custom_cfg_data']);
        //Provide alternate Configuration file instead of the one from the hard drive
        if (!empty($phone_info['template_data_info']['config_files_override'])) {
            $temp = unserialize($phone_info['template_data_info']['config_files_override']);
            foreach ($temp as $list) {
                $sql = "SELECT original_name,data FROM endpointman_custom_configs WHERE id = " . $list;
                $res = $endpoint->db->query($sql);
                if ($res->numRows()) {
                    $data = $endpoint->db->getRow($sql, array(), DB_FETCHMODE_ASSOC);
                    $provisioner_libary->config_files_override[$data['original_name']] = $data['data'];
                }
            }
        }
    } else {
        $global_custom_cfg_data = unserialize($phone_info['global_custom_cfg_data']);
        //Provide alternate Configuration file instead of the one from the hard drive
        if (!empty($phone_info['config_files_override'])) {
            $temp = unserialize($phone_info['config_files_override']);
            foreach ($temp as $list) {
                $sql = "SELECT original_name,data FROM endpointman_custom_configs WHERE id = " . $list;
                $res = $endpoint->db->query($sql);
                if ($res->numRows()) {
                    $data = $endpoint->db->getRow($sql, array(), DB_FETCHMODE_ASSOC);
                    $provisioner_libary->config_files_override[$data['original_name']] = $data['data'];
                }
            }
        }
    }

    if (!empty($global_custom_cfg_data)) {
        if (array_key_exists('data', $global_custom_cfg_data)) {
            $global_custom_cfg_ari = $global_custom_cfg_data['ari'];
            $global_custom_cfg_data = $global_custom_cfg_data['data'];
        } else {
            $global_custom_cfg_data = array();
            $global_custom_cfg_ari = array();
        }
    }

    $new_template_data = array();
    $line_ops = array();
    if (is_array($global_custom_cfg_data)) {
        foreach ($global_custom_cfg_data as $key => $data) {
            $full_key = $key;
            $key = explode('|', $key);
            $count = count($key);
            switch ($count) {
                case 1:
                    if (($endpoint->global_cfg['enable_ari'] == 1) AND (isset($global_custom_cfg_ari[$full_key])) AND (isset($global_user_cfg_data[$full_key]))) {
                        $new_template_data[$full_key] = $global_user_cfg_data[$full_key];
                    } else {
                        $new_template_data[$full_key] = $global_custom_cfg_data[$full_key];
                    }
                    break;
                case 2:
                    $breaks = explode('_', $key[1]);
                    if (($endpoint->global_cfg['enable_ari'] == 1) AND (isset($global_custom_cfg_ari[$full_key])) AND (isset($global_user_cfg_data[$full_key]))) {
                        $new_template_data[$breaks[0]][$breaks[2]][$breaks[1]] = $global_user_cfg_data[$full_key];
                    } else {
                        $new_template_data[$breaks[0]][$breaks[2]][$breaks[1]] = $global_custom_cfg_data[$full_key];
                    }
                    break;
                case 3:
                    if (($endpoint->global_cfg['enable_ari'] == 1) AND (isset($global_custom_cfg_ari[$full_key])) AND (isset($global_user_cfg_data[$full_key]))) {
                        $line_ops[$key[1]][$key[2]] = $global_user_cfg_data[$full_key];
                    } else {
                        $line_ops[$key[1]][$key[2]] = $global_custom_cfg_data[$full_key];
                    }
                    break;
            }
        }
    }

    //Loop through Lines!
    foreach ($phone_info['line'] as $line) {
        $provisioner_libary->lines[$line['line']] = array('ext' => $line['ext'], 'secret' => $line['secret'], 'displayname' => $line['description']);
    }

    //testing this out
    foreach ($line_ops as $key => $data) {
        if (isset($line_ops[$key])) {
            $provisioner_libary->lines[$key]['options'] = $line_ops[$key];
        }
    }

    $provisioner_libary->server_type = 'dynamic';
    $provisioner_libary->provisioning_type = 'http';
    $new_template_data['provisioning_path'] = "provisioning";

    //Set Variables according to the template_data files included. We can include different template.xml files within family_data.xml also one can create
    //template_data_custom.xml which will get included or template_data_<model_name>_custom.xml which will also get included
    //line 'global' will set variables that aren't line dependant
    $provisioner_libary->options = $new_template_data;

    //Setting a line variable here...these aren't defined in the template_data.xml file yet. however they will still be parsed
    //and if they have defaults assigned in a future template_data.xml or in the config file using pipes (|) those will be used, pipes take precedence
    $provisioner_libary->processor_info = "EndPoint Manager Version " . $endpoint->global_cfg['version'];
    
    $files = $provisioner_libary->generate_config();
    
    if(array_key_exists($requested_file, $files)) {
        echo $files[$requested_file];
    } else {
        header("HTTP/1.0 404 Not Found");
        die();
    }
}