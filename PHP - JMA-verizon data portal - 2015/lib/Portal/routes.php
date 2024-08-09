<?php
// Landing Page
$app->map('/', '\Portal\Controller\Home::default_controller')->via('GET');

// misc routes
$app->notFound('\Portal\Controller\Misc::misc_notfound_controller');

if (isJMA()) {
    $app->map('/phpinfo', function () {
        phpinfo();
    })->via('GET');
    $app->map('/debug', '\Portal\Controller\Misc::misc_debug_controller')->via('GET');
}
$app->map('/download/:loc/:filename(/:temp_name)', '\Portal\Controller\Misc::misc_download_controller')->via('GET');
$app->map('/upload', '\Portal\Controller\Misc::misc_upload_controller')->via('POST');
$app->map('/sitemap', '\Portal\Controller\Misc::misc_sitemap_controller')->via('GET');
$app->map('/upload-profile-image', '\Portal\Controller\Misc::misc_upload_profile_image_controller')->via('POST');

// admin group
$app->group('/admin', function () use ($app) {
    // login/logout
    $app->map('/login', '\Portal\Controller\Admin::admin_login_controller')->via('GET', 'POST');
    $app->map('/logout', '\Portal\Controller\Admin::admin_logout_controller')->via('GET');
    $app->map('/profile', '\Portal\Controller\Admin::admin_profile_controller')->via('GET', 'POST');
    $app->map('/verify/:email/:hash', '\Portal\Controller\Admin::admin_verify_controller')->via('GET');

    // manage users group
    if (isJMA()) {
        $app->group('/manage-users', function () use ($app) {
            $app->map('/', '\Portal\Controller\Admin::admin_manage_users_controller')->via('GET');
            $app->map('/new', '\Portal\Controller\AdminManageUser::admin_manageusr_new_controller')->via('GET', 'POST');
            $app->map('/role-management', '\Portal\Controller\AdminManageUser::admin_manageusr_role_management_controller')->via('GET', 'POST');
            $app->map('/permission-management', '\Portal\Controller\AdminManageUser::admin_manageusr_perm_management_controller')->via('GET', 'POST');
            $app->map('/asignment-management', '\Portal\Controller\AdminManageUser::admin_manageusr_assign_management_controller')->via('GET', 'POST');
            $app->map('/toggle-adm/:user_id', '\Portal\Controller\AdminManageUser::admin_manageusr_toggle_admin_controller')->via('GET');
            $app->map('/toggle-activated/:user_id', '\Portal\Controller\AdminManageUser::admin_manageusr_toggle_activated_controller')->via('GET');
            $app->map('/delete/:user_id', '\Portal\Controller\AdminManageUser::admin_manageusr_del_controller')->via('GET');
            $app->map('/change-role/:user_id/:role', '\Portal\Controller\AdminManageUser::admin_manageusr_change_role_controller')->via('GET', 'POST');
        });
    }
});

// Node Tools group
$app->group('/node-tools', function () use ($app) {
    if (isJMA()) {
        $app->map('/new', '\Portal\Controller\NodeTools::new_node')->via('GET', 'POST');
        $app->map('/open', '\Portal\Controller\NodeTools::incomplete_node')->via('GET', 'POST');
        $app->map('/wizard', '\Portal\Controller\NodeTools::wizard')->via('GET', 'POST');
        $app->group('/save-tab', function () use ($app) {
            $app->map('/site-info', '\Portal\Controller\NodeTools::site_info')->via('POST');
            //$app->map('/async', '\Portal\Controller\NodeTools::async')->via('POST');
            $app->map('/card-slotting', '\Portal\Controller\NodeTools::card_slotting')->via('POST');
            $app->map('/node-dev-names', '\Portal\Controller\NodeTools::node_dev_names')->via('POST');
            $app->map('/circuits', '\Portal\Controller\NodeTools::circuits')->via('POST');
            $app->map('/custaddr', '\Portal\Controller\NodeTools::custaddr')->via('POST');
            $app->map('/idn', '\Portal\Controller\NodeTools::idn')->via('POST');
            $app->map('/pubip', '\Portal\Controller\NodeTools::pubip')->via('POST');
            $app->map('/priip', '\Portal\Controller\NodeTools::priip')->via('POST');
            $app->map('/ipschema', '\Portal\Controller\NodeTools::ipschema')->via('POST');
            $app->map('/mx960', '\Portal\Controller\NodeTools::mx960')->via('POST');
            $app->map('/ns5400', '\Portal\Controller\NodeTools::ns5400')->via('POST');
            $app->map('/netcon', '\Portal\Controller\NodeTools::netcon')->via('POST');
            $app->map('/dns-syslog', '\Portal\Controller\NodeTools::dns_syslog')->via('POST');
        });
    }
    //$app->map('/deactiv-vsn', '\Portal\Controller\NodeTools::deactivate_vsn')->via('GET', 'POST');
    $app->map('/deactiv-node', '\Portal\Controller\NodeTools::deactivate_node')->via('GET', 'POST');
    $app->map('/activ-node', '\Portal\Controller\NodeTools::activate_node')->via('GET', 'POST');
});

//  Admin Tools group
if (isAdmin()) {
    $app->group('/admin-tools', function () use ($app) {
        $app->map('/delete-node', '\Portal\Controller\AdminTools::delete_node')->via('GET', 'POST');
        $app->map('/delete-node2', '\Portal\Controller\AdminTools::delete_node2')->via('GET', 'POST');
        $app->map('/master-tags(/:type)', '\Portal\Controller\AdminTools::master_tags_controller')->via('GET');
        $app->map('/vsn-editor', '\Portal\Controller\AdminTools::vsn_editor_controller')->via('GET');
        $app->map('/del-vsn', '\Portal\Controller\AdminTools::remove_vsn')->via('GET', 'POST');

        // Services Health check group
        /*
        $app->group('/health-check', function () use ($app) {
            $app->map('/apc-cache', '\Portal\HealthCheck\APC::do_check')->via('GET');
        });
        */
    });
}

// Config Tools group
if (isJMA()) {
    $app->group('/config', function () use ($app) {
        $app->map('/', '\Portal\Controller\ConfigTools::config_mgr_controller')->via('GET', 'POST');
        $app->map('/mx_builder', '\Portal\Controller\ConfigTools::mx_builder_controller')->via('POST');
    });
}

// reports group
$app->group('/reports', function () use ($app) {
    $app->map('/port-usage', '\Portal\Controller\Reports::port_usage_controller')->via('GET');
    $app->map('/ip-subnet-usage', '\Portal\Controller\Reports::ip_subnet_usage_controller')->via('GET');
    $app->map('/node-vsns', '\Portal\Controller\Reports::node_vsns_controller')->via('GET');
    $app->map('/node-mop', '\Portal\Controller\Reports::node_mop_controller')->via('GET');
    $app->map('/site-validation', '\Portal\Controller\Reports::site_validation_controller')->via('GET');
    $app->map('/ter', '\Portal\Controller\Reports::test_exit_report')->via('GET');
    $app->map('/globalsearch', '\Portal\Controller\Reports::global_search_controller')->via('GET');
});

// node group
// TODO: this where the node display method is call
$app->group('/node', function () use ($app) {
    // $app->map('/browser(/:filter)', '\Portal\Controller\Node::browser')->via('GET');
    // $app->map('/mop/(:action)', '\Portal\Controller\Node::mop_manipulation')->via('POST');
    // $app->map('/ispec/(:action)', '\Portal\Controller\Node::ispec_manipulation')->via('POST');
    // $app->map('/:site(/:type)', '\Portal\Controller\Node::display')->via('GET', 'POST');
    $app->map('/browser(/:filter)', function ($filter = '') {
        $controller = new \Portal\Controllers\NodeController();
        $controller->browser($filter);
    })->via('GET');
    $app->map('/mop/(:action)', function ($action = '') {
        $controller = new \Portal\Controllers\NodeController();
        $controller->mopManipulation($action);
    })->via('POST');
    $app->map('/ispec/(:action)', function ($action = '') {
        $controller = new \Portal\Controllers\NodeController();
        $controller->ispecManipulation($action);
    })->via('POST');
    $app->map('/:site(/:type)', function ($site, $type = 'all') {
        $controller = new \Portal\Controllers\NodeController();
        $controller->index($site, $type);
    })->via('GET', 'POST');
});

/**
 * api routes
 */
$app->group('/api', function () use ($app) {

    /**
     * Customer Addressing CRUD Operations
     */
    $app->group('/customerAddressing', function () use ($app) {
        $app->map('/read/:siteName(/:vsn)(/:type)', function ($siteName, $vsn = 'all', $type = 'all') {
            $controller = new \Portal\Controllers\CustomerAddressingApiController($type, $siteName);
            $controller->read($siteName, $vsn, $type);
        })->via('GET', 'POST');

        $app->post('/update/:id(/:type)', function ($id, $type='all') use ($app) {
            $json = $app->request->getBody();

            $controller = new \Portal\Controllers\CustomerAddressingApiController($type);
            $controller->update($id, $json, $type);
        });

        $app->post('/delete(/:id)(/:type)', function ($id=0, $type='all') use ($app) {
            $json = $app->request->getBody();
            if($json == null){
                $json = '[' . $id . ']'; //["1906", "1907"]
            }
            $controller = new \Portal\Controllers\CustomerAddressingApiController($type);
            $controller->delete($json);
        });

        $app->post('/create(/:type)', function ($type='all') use ($app) {
            $json = $app->request->getBody();

            $controller = new \Portal\Controllers\CustomerAddressingApiController($type);
            $controller->create($json);
        });
    });

    /**
     * IP Schema CRUD Operations
     */
    $app->group('/ipschema', function () use ($app) {
        $app->map('/read/:siteName(/:vsn)(/:type)', function ($siteName, $vsn = 'all', $type = 'all') {
            $controller = new \Portal\Controllers\IpSchemaApiController($type, $siteName);
            $controller->read($siteName, $vsn, $type);
        })->via('GET', 'POST');

        $app->post('/update/:id(/:type)', function ($id, $type='all') use ($app) {
            $json = $app->request->getBody();

            $controller = new \Portal\Controllers\IpSchemaApiController($type);
            $controller->update($id, $json, $type);
        });

        $app->post('/delete(/:id)(/:type)', function ($id=0, $type='all') use ($app) {
            $json = $app->request->getBody();
            if($json == null){
                $json = '[' . $id . ']'; //["1906", "1907"]
            }
            $controller = new \Portal\Controllers\IpSchemaApiController($type);
            $controller->delete($json);
        });

        $app->post('/create(/:type)', function ($type='all') use ($app) {
            $json = $app->request->getBody();

            $controller = new \Portal\Controllers\IpSchemaApiController($type);
            $controller->create($json);
        });
    });

    /**
     * MX960 Port Assignments CRUD Operations
     */
    $app->group('/mx960PortAssignments', function () use ($app) {
        $app->map('/read/:siteName/:mx(/:vsn)(/:type)', function ($siteName, $mx, $vsn = 'all', $type = 'all') {
            $controller = new \Portal\Controllers\Mx960ApiController($type, $siteName);
            $controller->read($siteName, $mx, $vsn, $type);
        })->via('GET', 'POST');

        $app->post('/update/:id(/:type)', function ($id, $type='all') use ($app) {
            $json = $app->request->getBody();

            $controller = new \Portal\Controllers\Mx960ApiController($type);
            $controller->update($id, $json, $type);
        });

        $app->post('/delete(/:id)(/:type)', function ($id=0, $type='all') use ($app) {
            $json = $app->request->getBody();
            if($json == null){
                $json = '[' . $id . ']'; //["1906", "1907"]
            }
            $controller = new \Portal\Controllers\Mx960ApiController($type);
            $controller->delete($json);
        });

        $app->post('/create(/:type)', function ($type='all') use ($app) {
            $json = $app->request->getBody();

            $controller = new \Portal\Controllers\Mx960ApiController($type);
            $controller->create($json);
        });
    });

    /**
     * NS5400 Port Assignments CRUD Operations
     */
    $app->group('/ns5400PortAssignments', function () use ($app) {
        $app->map('/read/:siteName/:ns(/:vsn)(/:type)', function ($siteName, $ns, $vsn = 'all', $type = 'all') {
            $controller = new \Portal\Controllers\Ns5400ApiController($type, $siteName);
            $controller->read($siteName, $ns, $vsn, $type);
        })->via('GET', 'POST');

        $app->post('/update/:id(/:type)', function ($id, $type='all') use ($app) {
            $json = $app->request->getBody();

            $controller = new \Portal\Controllers\Ns5400ApiController($type);
            $controller->update($id, $json, $type);
        });

        $app->post('/delete(/:id)(/:type)', function ($id=0, $type='all') use ($app) {
            $json = $app->request->getBody();
            if($json == null){
                $json = '[' . $id . ']'; //["1906", "1907"]
            }
            $controller = new \Portal\Controllers\Ns5400ApiController($type);
            $controller->delete($json);
        });

        $app->post('/create(/:type)', function ($type='all') use ($app) {
            $json = $app->request->getBody();

            $controller = new \Portal\Controllers\Ns5400ApiController($type);
            $controller->create($json);
        });
    });

    /**
     * Network Connections CRUD Operations
     */
    $app->group('/networkConnections', function () use ($app) {
        $app->map('/read/:siteName(/:vsn)(/:type)', function ($siteName, $vsn = 'all', $type = 'all') {
            $controller = new \Portal\Controllers\NetworkConnectionsApiController($type, $siteName);
            $controller->read($siteName, $vsn, $type);
        })->via('GET', 'POST');

        $app->post('/update/:id(/:type)', function ($id, $type='all') use ($app) {
            $json = $app->request->getBody();

            $controller = new \Portal\Controllers\NetworkConnectionsApiController($type);
            $controller->update($id, $json, $type);
        });

        $app->post('/delete(/:id)(/:type)', function ($id=0, $type='all') use ($app) {
            $json = $app->request->getBody();
            if($json == null){
                $json = '[' . $id . ']'; //["1906", "1907"]
            }
            $controller = new \Portal\Controllers\NetworkConnectionsApiController($type);
            $controller->delete($json);
        });

        $app->post('/create(/:type)', function ($type='all') use ($app) {
            $json = $app->request->getBody();

            $controller = new \Portal\Controllers\NetworkConnectionsApiController($type);
            $controller->create($json);
        });
    });

    /**
     * Card Slotting CRUD Operations
     */
    $app->group('/cardSlotting', function () use ($app) {
        $app->map('/read/:siteName(/:vsn)(/:type)', function ($siteName, $vsn = 'all', $type = 'all') {
            $controller = new \Portal\Controllers\CardSlottingApiController($type, $siteName);
            $controller->read($siteName, $vsn, $type);
        })->via('GET', 'POST');

        $app->post('/update/:id(/:type)', function ($id, $type='all') use ($app) {
            $json = $app->request->getBody();

            $controller = new \Portal\Controllers\CardSlottingApiController($type);
            $controller->update($id, $json, $type);
        });

        $app->post('/delete(/:id)(/:type)', function ($id=0, $type='all') use ($app) {
            $json = $app->request->getBody();
            if($json == null){
                $json = '[' . $id . ']'; //["1906", "1907"]
            }
            $controller = new \Portal\Controllers\CardSlottingApiController($type);
            $controller->delete($json);
        });

        $app->post('/create(/:type)', function ($type='all') use ($app) {
            $json = $app->request->getBody();

            $controller = new \Portal\Controllers\CardSlottingApiController($type);
            $controller->create($json);
        });
    });

    /**
     * Card Slotting CRUD Operations
     */
    $app->group('/nodeDeviceName', function () use ($app) {
        $app->map('/read/:siteName(/:vsn)(/:type)', function ($siteName, $vsn = 'all', $type = 'all') {
            $controller = new \Portal\Controllers\NodeDeviceNameApiController($type, $siteName);
            $controller->read($siteName, $vsn, $type);
        })->via('GET', 'POST');

        $app->post('/update/:id(/:type)', function ($id, $type='all') use ($app) {
            $json = $app->request->getBody();

            $controller = new \Portal\Controllers\NodeDeviceNameApiController($type);
            $controller->update($id, $json, $type);
        });

        $app->post('/delete(/:id)(/:type)', function ($id=0, $type='all') use ($app) {
            $json = $app->request->getBody();
            if($json == null){
                $json = '[' . $id . ']'; //["1906", "1907"]
            }
            $controller = new \Portal\Controllers\NodeDeviceNameApiController($type);
            $controller->delete($json);
        });

        $app->post('/create(/:type)', function ($type='all') use ($app) {
            $json = $app->request->getBody();

            $controller = new \Portal\Controllers\NodeDeviceNameApiController($type);
            $controller->create($json);
        });
    });

    /**
     * IP Private CRUD Operations
     */
    $app->group('/ipPrivate', function () use ($app) {
        $app->map('/read/:siteName(/:vsn)(/:type)', function ($siteName, $vsn = 'all', $type = 'all') {
            $controller = new \Portal\Controllers\IpPrivateApiController($type, $siteName);
            $controller->read($siteName, $vsn, $type);
        })->via('GET', 'POST');

        $app->post('/update/:id(/:type)', function ($id, $type='all') use ($app) {
            $json = $app->request->getBody();

            $controller = new \Portal\Controllers\IpPrivateApiController($type);
            $controller->update($id, $json, $type);
        });

        $app->post('/delete(/:id)(/:type)', function ($id=0, $type='all') use ($app) {
            $json = $app->request->getBody();
            if($json == null){
                $json = '[' . $id . ']'; //["1906", "1907"]
            }
            $controller = new \Portal\Controllers\IpPrivateApiController($type);
            $controller->delete($json);
        });

        $app->post('/create(/:type)', function ($type='all') use ($app) {
            $json = $app->request->getBody();

            $controller = new \Portal\Controllers\IpPrivateApiController($type);
            $controller->create($json);
        });
    });

    /**
     * IP Public CRUD Operations
     */
    $app->group('/ipPublic', function () use ($app) {
        $app->map('/read/:siteName(/:vsn)(/:type)', function ($siteName, $vsn = 'all', $type = 'all') {
            $controller = new \Portal\Controllers\IpPublicApiController($type, $siteName);
            $controller->read($siteName, $vsn, $type);
        })->via('GET', 'POST');

        $app->post('/update/:id(/:type)', function ($id, $type='all') use ($app) {
            $json = $app->request->getBody();

            $controller = new \Portal\Controllers\IpPublicApiController($type);
            $controller->update($id, $json, $type);
        });

        $app->post('/delete(/:id)(/:type)', function ($id=0, $type='all') use ($app) {
            $json = $app->request->getBody();
            if($json == null){
                $json = '[' . $id . ']'; //["1906", "1907"]
            }
            $controller = new \Portal\Controllers\IpPublicApiController($type);
            $controller->delete($json);
        });

        $app->post('/create(/:type)', function ($type='all') use ($app) {
            $json = $app->request->getBody();

            $controller = new \Portal\Controllers\IpPublicApiController($type);
            $controller->create($json);
        });
    });

    /**
     * IP Idn CRUD Operations
     */
    $app->group('/ipIdn', function () use ($app) {
        $app->map('/read/:siteName(/:vsn)(/:type)', function ($siteName, $vsn = 'all', $type = 'all') {
            $controller = new \Portal\Controllers\IpIdnApiController($type, $siteName);
            $controller->read($siteName, $vsn, $type);
        })->via('GET', 'POST');

        $app->post('/update/:id(/:type)', function ($id, $type='all') use ($app) {
            $json = $app->request->getBody();

            $controller = new \Portal\Controllers\IpIdnApiController($type);
            $controller->update($id, $json, $type);
        });

        $app->post('/delete(/:id)(/:type)', function ($id=0, $type='all') use ($app) {
            $json = $app->request->getBody();
            if($json == null){
                $json = '[' . $id . ']'; //["1906", "1907"]
            }
            $controller = new \Portal\Controllers\IpIdnApiController($type);
            $controller->delete($json);
        });

        $app->post('/create(/:type)', function ($type='all') use ($app) {
            $json = $app->request->getBody();

            $controller = new \Portal\Controllers\IpIdnApiController($type);
            $controller->create($json);
        });
    });

    /**
     * Dns Trap Log CRUD Operations
     */
    $app->group('/dnsTrapLog', function () use ($app) {
        $app->map('/read/:siteName(/:vsn)(/:type)', function ($siteName, $vsn = 'all', $type = 'all') {
            $controller = new \Portal\Controllers\DnsTrapLogApiController($type, $siteName);
            $controller->read($siteName, $vsn, $type);
        })->via('GET', 'POST');

        $app->post('/update/:id(/:type)', function ($id, $type='all') use ($app) {
            $json = $app->request->getBody();

            $controller = new \Portal\Controllers\DnsTrapLogApiController($type);
            $controller->update($id, $json, $type);
        });

        $app->post('/delete(/:id)(/:type)', function ($id=0, $type='all') use ($app) {
            $json = $app->request->getBody();
            if($json == null){
                $json = '[' . $id . ']'; //["1906", "1907"]
            }
            $controller = new \Portal\Controllers\DnsTrapLogApiController($type);
            $controller->delete($json);
        });

        $app->post('/create(/:type)', function ($type='all') use ($app) {
            $json = $app->request->getBody();

            $controller = new \Portal\Controllers\DnsTrapLogApiController($type);
            $controller->create($json);
        });
    });

    /**
     * Subnet Adv CRUD Operations
     */
    $app->group('/subnetAdv', function () use ($app) {
        $app->map('/read/:siteName(/:vsn)(/:type)', function ($siteName, $vsn = 'all', $type = 'all') {
            $controller = new \Portal\Controllers\SubnetAdvApiController($type, $siteName);
            $controller->read($siteName, $vsn, $type);
        })->via('GET', 'POST');

        $app->post('/update/:id(/:type)', function ($id, $type='all') use ($app) {
            $json = $app->request->getBody();

            $controller = new \Portal\Controllers\SubnetAdvApiController($type);
            $controller->update($id, $json, $type);
        });

        $app->post('/delete(/:id)(/:type)', function ($id=0, $type='all') use ($app) {
            $json = $app->request->getBody();
            if($json == null){
                $json = '[' . $id . ']'; //["1906", "1907"]
            }
            $controller = new \Portal\Controllers\SubnetAdvApiController($type);
            $controller->delete($json);
        });

        $app->post('/create(/:type)', function ($type='all') use ($app) {
            $json = $app->request->getBody();

            $controller = new \Portal\Controllers\SubnetAdvApiController($type);
            $controller->create($json);
        });
    });

    /**
     * Async CRUD Operations
     */
    $app->group('/async', function () use ($app) {
        $app->map('/read/:siteName(/:vsn)(/:type)', function ($siteName, $vsn = 'all', $type = 'all') {
            $controller = new \Portal\Controllers\AsyncApiController($type, $siteName);
            $controller->read($siteName, $vsn, $type);
        })->via('GET', 'POST');

        $app->post('/update/:id(/:type)', function ($id, $type='all') use ($app) {
            $json = $app->request->getBody();

            $controller = new \Portal\Controllers\AsyncApiController($type);
            $controller->update($id, $json, $type);
        });

        $app->post('/delete(/:id)(/:type)', function ($id=0, $type='all') use ($app) {
            $json = $app->request->getBody();
            if($json == null){
                $json = '[' . $id . ']'; //["1906", "1907"]
            }
            $controller = new \Portal\Controllers\AsyncApiController($type);
            $controller->delete($json);
        });

        $app->post('/create(/:type)', function ($type='all') use ($app) {
            $json = $app->request->getBody();

            $controller = new \Portal\Controllers\AsyncApiController($type);
            $controller->create($json);
        });
    });

    /**
     * TDR Info CRUD Operations
     */
    $app->group('/tdr', function () use ($app) {
        $app->map('/read/:siteName(/:vsn)(/:type)', function ($siteName, $vsn = 'all', $type = 'all') {
            $controller = new \Portal\Controllers\TdrApiController($type, $siteName);
            $controller->read($siteName, $vsn, $type);
        })->via('GET', 'POST');

        $app->post('/update/:id(/:type)', function ($id, $type='all') use ($app) {
            $json = $app->request->getBody();

            $controller = new \Portal\Controllers\TdrApiController($type);
            $controller->update($id, $json, $type);
        });

        $app->post('/delete(/:id)(/:type)', function ($id=0, $type='all') use ($app) {
            $json = $app->request->getBody();
            if($json == null){
                $json = '[' . $id . ']'; //["1906", "1907"]
            }
            $controller = new \Portal\Controllers\TdrApiController($type);
            $controller->delete($json);
        });

        $app->post('/create(/:type)', function ($type='all') use ($app) {
            $json = $app->request->getBody();

            $controller = new \Portal\Controllers\TdrApiController($type);
            $controller->create($json);
        });
    });

    /**
     * TDR Info CRUD Operations
     */
    $app->group('/siteInfo', function () use ($app) {
        $app->map('/read/:siteName(/:vsn)(/:type)', function ($siteName, $vsn = 'all', $type = 'all') {
            $controller = new \Portal\Controllers\SiteInfoApiController($type, $siteName);
            $controller->read($siteName, $vsn, $type);
        })->via('GET', 'POST');
    });

    /**
     * Get Master Tags
     */
    $app->map('/getMasterTags/:val(/:category)(/:type)', function ($val, $category, $type = 'all') {
        $controller = new \Portal\Controllers\ApiController($type);
        $controller->getMasterTags($val);
    })->via('GET', 'POST');
});

// ajax group
$app->group('/ajax', function () use ($app) {

    $app->group('/reports', function () use ($app) {
        $app->map('/fglobalsearch', '\Portal\Controller\Reports::f_global_search')->via('POST');
    });

    $app->map('/site_info', '\Portal\Controller\Ajax\Site::ajax_site_info_controller')->via('GET', 'POST');
    $app->map('/master-tags(/:query)', '\Portal\Controller\Ajax\Tags::ajax_master_tags_suggest_controller')->via('GET');

    $app->group('/site-tools', function () use ($app) {
        $app->map('/self-tasks', '\Portal\Controller\Ajax\Site::ajax_track_self_controller')->via('GET');
        $app->map('/track-overview', '\Portal\Controller\Ajax\Site::ajax_track_overview_controller')->via('GET');
    });

    $app->group('/site-photos', function () use ($app) {
        $app->map('/mkzip', '\Portal\Controller\Ajax\Site::ajax_site_photos_mkzip_controller')->via('GET');
        $app->map('/delete', '\Portal\Controller\Ajax\Site::ajax_site_photos_delete_controller')->via('GET', 'POST');
    });

    $app->group('/datatables', function () use ($app) {
        $app->map('/async', '\Portal\Controller\Ajax\Devices::ajax_async_controller')->via('GET', 'POST');
        $app->map('/nodedevicenames', '\Portal\Controller\Ajax\Devices::ajax_nodedevicenames_controller')->via('GET', 'POST');
        $app->map('/tdrinfo', '\Portal\Controller\Ajax\Devices::ajax_tdrinfo_controller')->via('GET', 'POST');
        $app->map('/mastertags', '\Portal\Controller\Ajax\Tags::ajax_mastertags_controller')->via('GET', 'POST');
        $app->map('/ter', '\Portal\Controller\Ajax\Site::ajax_ter_controller')->via('GET', 'POST');
        $app->map('/customeraddressing', '\Portal\Controller\Ajax\Customer::ajax_customeraddressing_controller')->via('GET', 'POST');
        $app->map('/networkconn', '\Portal\Controller\Ajax\Network::ajax_networkconn_controller')->via('GET', 'POST');
        $app->map('/cust_ckt', '\Portal\Controller\Ajax\Customer::ajax_custckt_controller')->via('GET', 'POST');
        $app->map('/mx960/:mx_num', '\Portal\Controller\Ajax\Devices::ajax_mx960_controller')->via('GET', 'POST');
        $app->map('/ns/:ns_num', '\Portal\Controller\Ajax\Network::ajax_ns_controller')->via('GET', 'POST');
        $app->map('/ipschema', '\Portal\Controller\Ajax\Network::ajax_ipschema_controller')->via('GET', 'POST');
        $app->map('/pubip', '\Portal\Controller\Ajax\Network::ajax_pubip_controller')->via('GET', 'POST');
        $app->map('/idnip', '\Portal\Controller\Ajax\Network::ajax_idnip_controller')->via('GET', 'POST');
        $app->map('/privateip', '\Portal\Controller\Ajax\Network::ajax_privateip_controller')->via('GET', 'POST');
        $app->map('/sitestatus', '\Portal\Controller\Ajax\Site::ajax_sitestatus_controller')->via('GET', 'POST');
        $app->map('/subnet_details', '\Portal\Controller\Ajax\Network::ajax_subnet_details_controller')->via('GET', 'POST');
        $app->map('/dns_trap', '\Portal\Controller\Ajax\Network::ajax_dns_trap_controller')->via('GET', 'POST');
        $app->map('/subnetadv', '\Portal\Controller\Ajax\Network::ajax_subnetadv_controller')->via('GET', 'POST');
        $app->map('/cardslotting', '\Portal\Controller\Ajax\Devices::ajax_cardslotting_controller')->via('GET', 'POST');
        $app->map('/usermanagement_roles', '\Portal\Controller\Ajax\UserManagement::ajax_usermanagement_roles_controller')->via('GET', 'POST');
        $app->map('/usermanagement_perms', '\Portal\Controller\Ajax\UserManagement::ajax_usermanagement_perms_controller')->via('GET', 'POST');


        // audit routes
        $app->map('/tabs-audit/:audit', '\Portal\Controller\Ajax\Audit::ajax_site_info_audit_controller')->via('GET', 'POST');
        $app->map('/mx960-audit/:mx_num/:audit', '\Portal\Controller\Ajax\Audit::ajax_mx960_audit_controller')->via('GET', 'POST');
        $app->map('/async-audit/:audit', '\Portal\Controller\Ajax\Audit::ajax_async_audit_controller')->via('GET', 'POST');
        $app->map('/tdr-audit/:audit', '\Portal\Controller\Ajax\Audit::ajax_tdrinfo_audit_controller')->via('GET', 'POST');
        $app->map('/mastertags-audit/:audit', '\Portal\Controller\Ajax\Audit::ajax_mastertags_audit_controller')->via('GET', 'POST');
        $app->map('/cust-addr-audit/:audit', '\Portal\Controller\Ajax\Audit::ajax_customeraddressing_audit_controller')->via('GET', 'POST');
        $app->map('/net-conn-audit/:audit', '\Portal\Controller\Ajax\Audit::ajax_networkconn_audit_controller')->via('GET', 'POST');
        $app->map('/ajax/datatables/cust-ckts-audit/:audit', '\Portal\Controller\Ajax\Audit::ajax_custckt_audit_controller')->via('GET', 'POST');
        $app->map('/ns-audit/:ns_num/:audit', '\Portal\Controller\Ajax\Audit::ajax_ns_audit_controller')->via('GET', 'POST');
        $app->map('/ipschema-audit/:audit', '\Portal\Controller\Ajax\Audit::ajax_ipschema_audit_controller')->via('GET', 'POST');
        $app->map('/pubip-audit/:audit', '\Portal\Controller\Ajax\Audit::ajax_pubip_audit_controller')->via('GET', 'POST');
        $app->map('/idnip-audit/:audit', '\Portal\Controller\Ajax\Audit::ajax_idnip_audit_controller')->via('GET', 'POST');
        $app->map('/privateip-audit/:audit', '\Portal\Controller\Ajax\Audit::ajax_privateip_audit_controller')->via('GET', 'POST');
        $app->map('/dns-trap-audit/:audit', '\Portal\Controller\Ajax\Audit::ajax_dns_trap_audit_controller')->via('GET', 'POST');
        $app->map('/subnet-adv-audit/:audit', '\Portal\Controller\Ajax\Audit::ajax_subnetadv_audit_controller')->via('GET', 'POST');
        $app->map('/cardslotting-audit/:audit', '\Portal\Controller\Ajax\Audit::ajax_cardslotting_audit_controller')->via('GET', 'POST');
    });
});

// testing tools
// nothing major, just wanted to test some model logic
$app->group('/test', function () use ($app) {
    $app->map('/models/:model', function ($model) {
        include 'tests/models/' . $model . 'Test.php';
    })->via('GET', 'POST');
    $app->map('/models/viewmodels/:model', function ($model) {
        include 'tests/models/viewmodels/' . $model . 'Test.php';
    })->via('GET', 'POST');
});
