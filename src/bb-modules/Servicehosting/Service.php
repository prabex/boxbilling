<?php
/**
 * BoxBilling
 *
 * @copyright BoxBilling, Inc (http://www.boxbilling.com)
 * @license   Apache-2.0
 *
 * Copyright BoxBilling, Inc
 * This source file is subject to the Apache-2.0 License that is bundled
 * with this source code in the file LICENSE
 */

namespace Box\Mod\Servicehosting;
use Box\InjectionAwareInterface;
use Box\MeteredInterface;

class Service implements InjectionAwareInterface, MeteredInterface
{
    /**
     * @var \Box_Di
     */
    protected $di = null;

    /**
     * @param \Box_Di $di
     */
    public function setDi($di)
    {
        $this->di = $di;
    }

    /**
     * @return \Box_Di
     */
    public function getDi()
    {
        return $this->di;
    }

    public function setUsage(\Model_ClientOrder $clientOrder)
    {
        if ($clientOrder->status != \Model_ClientOrder::STATUS_ACTIVE){
            return false;
        }
        $meteredBillingService = $this->di['mod_service']('MeteredBilling');
        $model = $meteredBillingService->create($clientOrder);

        $noteStatusToStartNewBilling = array(
            'Activated canceled order', 'Order unsuspended'
        );
        $orderStatus = $this->di['db']->findOne('ClientOrderStatus', 'client_order_id = :order_id ORDER BY id desc', array(':order_id' => $clientOrder->id));
        if (isset($orderStatus) && in_array($orderStatus->notes, $noteStatusToStartNewBilling)){
            $model->quantity = 0;
        }

        $meteredBillingService->save($model);
        return true;
    }

    public function stopUsage (\Model_ClientOrder $clientOrder)
    {
        $productModel = $this->di['db']->load('Product', $clientOrder->product_id);

        $meteredBillingService = $this->di['mod_service']('MeteredBilling');
        $meteredUsageModel = $meteredBillingService->findActiveProductUsage($clientOrder->client_id, $clientOrder->id, $productModel->id);
        if (!isset($meteredUsageModel)){
            error_log('Metered usage was not found');
            return false;
        }
        return $meteredBillingService->stopUsage($meteredUsageModel);
    }

    public function getCartProductTitle($product, array $data)
    {
        try {
            list($sld, $tld) = $this->_getDomainTuple($data);
            return __(':hosting for :domain', array(':hosting'=>$product->title, ':domain'=>$sld.$tld));
        } catch(\Exception $e) {
            // should never occur, but in case
            error_log($e->getMessage());
        }

        return $product->title;
    }
    
    public function validateOrderData(array &$data)
    {
        if(!isset($data['server_id'])) {
            throw new \Box_Exception('Hosting product is not configured completely. Configure server for hosting product.', null, 701);
        }
        if(!isset($data['hosting_plan_id'])) {
            throw new \Box_Exception('Hosting product is not configured completely. Configure hosting plan for hosting product.', null, 702);
        }
        if(!isset($data['sld']) || empty($data['sld'])) {
            throw new \Box_Exception('Domain name is not valid.', null, 703);
        }
        if(!isset($data['tld']) || empty($data['tld'])) {
            throw new \Box_Exception('Domain extension is not valid.', null, 704);
        }
    }

    /**
     *
     * @todo
     * @param \Model_ClientOrder $order
     * @return void
     */
    public function action_create(\Model_ClientOrder $order)
    {
        $orderService = $this->di['mod_service']('order');
        $c = $orderService->getConfig($order);
        $this->validateOrderData($c);

        $server = $this->di['db']->load('ServiceHostingServer', $c['server_id']);
        if(!$server instanceof \Model_ServiceHostingServer) {
            throw new \Box_Exception('Server from order configuration was not found');
        }

        $hp = $this->di['db']->load('ServiceHostingHp', $c['hosting_plan_id']);
        if(!$hp instanceof \Model_ServiceHostingHp) {
            throw new \Box_Exception('Hosting plan from order configuration was not found');
        }

        $model = $this->di['db']->dispense('ServiceHosting');
        $model->client_id = $order->client_id;
        $model->service_hosting_server_id = $server->id;
        $model->service_hosting_hp_id = $hp->id;
        $model->sld = $c['sld'];
        $model->tld = $c['tld'];
        $model->ip = $server->ip;
        $model->reseller = isset($c['reseller']) ? $c['reseller'] : FALSE;
        $model->created_at = date('c');
        $model->updated_at = date('c');
        $this->di['db']->store($model);

        return $model;
    }

    public function action_activate(\Model_ClientOrder $order)
    {
        $orderService = $this->di['mod_service']('order');
        $model = $orderService->getOrderService($order);
        if(!$model instanceof \Model_ServiceHosting) {
            throw new \Box_Exception('Could not activate order. Service was not created');
        }

        $pass = $this->di['tools']->generatePassword(10, 4);
        $c = $orderService->getConfig($order);
        if(isset($c['password']) && !empty($c['password'])) {
            $pass = $c['password'];
        }
        
        if(isset($c['username']) && !empty($c['username'])) {
            $username = $c['username'];
        } else {
            $username = $this->_generateUsername();
        }
        
        $model->username = $username;
        $model->pass = $pass;
        $this->di['db']->store($model);

        if(!isset($c['import']) || !$c['import']) {
            list($adapter, $account) = $this->_getAM($model);
            $adapter->createAccount($account);
        }
        
        return array(
            'username'  =>  $username,
            'password'  =>  $pass,
        );
    }

    /**
     *
     * @todo
     * @param \Model_ClientOrder $order
     * @return void
     */
    public function action_renew(\Model_ClientOrder $order)
    {
        // move expiration period to future
        $orderService = $this->di['mod_service']('order');
        $model = $orderService->getOrderService($order);
        if(!$model instanceof \RedBean_SimpleModel) {
            throw new \Box_Exception('Order :id has no active service', array(':id'=>$order->id));
        }
        //@todo ?

        $model->updated_at = date('c');
        $this->di['db']->store($model);
        return true;
    }

    /**
     * @param \Model_ClientOrder $order
     * @return void
     */
    public function action_suspend(\Model_ClientOrder $order)
    {
        $orderService = $this->di['mod_service']('order');
        $model = $orderService->getOrderService($order);
        if(!$model instanceof \RedBean_SimpleModel) {
            throw new \Box_Exception('Order :id has no active service', array(':id'=>$order->id));
        }
        list($adapter, $account) = $this->_getAM($model);
        $adapter->suspendAccount($account);

        $model->updated_at = date('c');
        $this->di['db']->store($model);
        return true;
    }

    /**
     * @param \Model_ClientOrder $order
     * @return void
     */
    public function action_unsuspend(\Model_ClientOrder $order)
    {
        $orderService = $this->di['mod_service']('order');
        $model = $orderService->getOrderService($order);
        if(!$model instanceof \RedBean_SimpleModel) {
            throw new \Box_Exception('Order :id has no active service', array(':id'=>$order->id));
        }
        list($adapter, $account) = $this->_getAM($model);
        $adapter->unsuspendAccount($account);

        $model->updated_at = date('c');
        $this->di['db']->store($model);
        return true;
    }

    /**
     * @param \Model_ClientOrder $order
     * @return void
     */
    public function action_cancel(\Model_ClientOrder $order)
    {
        $orderService = $this->di['mod_service']('order');
        $model = $orderService->getOrderService($order);
        if(!$model instanceof \RedBean_SimpleModel) {
            throw new \Box_Exception('Order :id has no active service', array(':id'=>$order->id));
        }
        list($adapter, $account) = $this->_getAM($model);
        $adapter->cancelAccount($account);

        $model->updated_at = date('c');
        $this->di['db']->store($model);
        return true;
    }

    /**
     * @param \Model_ClientOrder $order
     * @return void
     */
    public function action_uncancel(\Model_ClientOrder $order)
    {
        $this->action_create($order);
        return true;
    }

    /**
     * @param \Model_ClientOrder $order
     * @return void
     */
    public function action_delete(\Model_ClientOrder $order)
    {
        $orderService = $this->di['mod_service']('order');
        $service = $orderService->getOrderService($order);
        if($service instanceof \Model_ServiceHosting) {
            //cancel if not canceled
            if($order->status != \Model_ClientOrder::STATUS_CANCELED) {
                $this->action_cancel($order);
            }
            $this->di['db']->trash($service);
        }
    }

    public function changeAccountPlan(\Model_ClientOrder $order, \Model_ServiceHosting $model, \Model_ServiceHostingHp $hp)
    {
        if($this->_performOnService($order)){
            $package = $this->getServerPackage($hp);
            list($adapter, $account) = $this->_getAM($model);
            $adapter->changeAccountPackage($account, $package);
        }

        $model->service_hosting_hp_id = $hp->id;
        $model->updated_at = date('c');
        $this->di['db']->store($model);
        $this->di['logger']->info('Changed hosting plan of account #%s', $model->id);
        return TRUE;
    }

    public function changeAccountUsername(\Model_ClientOrder $order, \Model_ServiceHosting $model, $data)
    {
        if(!isset($data['username']) || empty($data['username'])) {
            throw new \Box_Exception('Account password is missing or is not valid');
        }

        $u = $data['username'];

        if($this->_performOnService($order)){
            list($adapter, $account) = $this->_getAM($model);
            $adapter->changeAccountUsername($account, $u);
        }
        
        $model->username = $u;
        $model->updated_at = date('c');
        $this->di['db']->store($model);

        $this->di['logger']->info('Changed hosting account %s username', $model->id);
        return TRUE;
    }

    public function changeAccountIp(\Model_ClientOrder $order, \Model_ServiceHosting $model, $data)
    {
        if(!isset($data['ip']) || empty($data['ip'])) {
            throw new \Box_Exception('Account ip is missing or is not valid');
        }

        $ip = $data['ip'];

        if($this->_performOnService($order)){
            list($adapter, $account) = $this->_getAM($model);
            $adapter->changeAccountIp($account, $ip);
        }
        
        $model->ip = $ip;
        $model->updated_at = date('c');
        $this->di['db']->store($model);
        $this->di['logger']->info('Changed hosting account %s ip', $model->id);
        return TRUE;
    }

    public function changeAccountDomain(\Model_ClientOrder $order, \Model_ServiceHosting $model, $data)
    {
        if(!isset($data['tld']) || empty($data['tld']) ||
           !isset($data['sld']) || empty($data['sld'])) {
            throw new \Box_Exception('Domain sld or tld is missing');
        }

        $sld = $data['sld'];
        $tld = $data['tld'];

        if($this->_performOnService($order)){
            list($adapter, $account) = $this->_getAM($model);
            $adapter->changeAccountDomain($account, $sld.$tld);
        }
        
        $model->sld = $sld;
        $model->tld = $tld;
        $model->updated_at = date('c');
        $this->di['db']->store($model);
        $this->di['logger']->info('Changed hosting account %s domain', $model->id);
        return TRUE;
    }

    public function changeAccountPassword(\Model_ClientOrder $order, \Model_ServiceHosting $model, $data)
    {
        if(!isset($data['password']) || !isset($data['password_confirm'])
                || $data['password'] != $data['password_confirm']) {
            throw new \Box_Exception('Account password is missing or is not valid');
        }

        $p = $data['password'];

        if($this->_performOnService($order)){
            list($adapter, $account) = $this->_getAM($model);
            $adapter->changeAccountPassword($account, $p);
        }

        $model->pass = $p;
        $model->updated_at = date('c');
        $this->di['db']->store($model);
        $this->di['logger']->info('Changed hosting account %s password', $model->id);
        return TRUE;
    }

    public function sync(\Model_ClientOrder $order, \Model_ServiceHosting $model)
    {
        list($adapter, $account) = $this->_getAM($model);
        $updated = $adapter->synchronizeAccount($account);

        if($account->getUsername() != $updated->getUsername()) {
            $model->username = $updated->getUsername();
        }

        if($account->getIp() != $updated->getIp()) {
            $model->ip = $updated->getIp();
        }
        
        $model->updated_at = date('c');
        $this->di['db']->store($model);
        $this->di['logger']->info('Synchronizing hosting account %s with server', $model->id);
        return TRUE;
    }

    private function _getDomainOrderId(\Model_ServiceHosting $model)
    {
        $orderService = $this->di['mod_service']('order');
        $o = $orderService->getServiceOrder($model);
        if($o instanceof \Model_ClientOrder ) {
            $c = $orderService->getConfig($o);
            if(isset($c['domain']) && isset($c['domain']['action'])) {
                $action = $c['domain']['action'];
                if($action == 'register' || $action == 'transfer') {
                   return $orderService->getRelatedOrderIdByType($o, 'domain');
                }
            }
        }
        return NULL;
    }

    private function _performOnService(\Model_ClientOrder $order)
    {
        return ($order->status != \Model_ClientOrder::STATUS_FAILED_SETUP);
    }
    
    /**
     * Generate username by domain
     *
     * @param string $domain
     */
    private function _generateUsername()
    {
        $num1 = rand(10000, 99999);
        $num2 = rand(10000, 99999);
        $username = $num1 . $num2;
        $username = substr($username, 0, 8); // max length 8
        $username[0] = "u";

        return $username;
    }

    public function _getAM(\Model_ServiceHosting $model, \Model_ServiceHostingHp $hp = null)
    {
        if(null === $hp) {
            $hp = $this->di['db']->getExistingModelById('ServiceHostingHp', $model->service_hosting_hp_id, 'Hosting plan not found');
        }

        $server = $this->di['db']->getExistingModelById('ServiceHostingServer', $model->service_hosting_server_id, 'Server not found');
        $c = $this->di['db']->getExistingModelById('Client', $model->client_id, 'Client not found');

        $hp_config = $hp->config;


        $client = $this->di['server_client'];
        $client
            ->setEmail($c->email)
            ->setFullName($c->getFullName())
            ->setCompany($c->company)
            ->setStreet($c->address_1)
            ->setZip($c->postcode)
            ->setCity($c->city)
            ->setState($c->state)
            ->setCountry($c->country)
            ->setTelephone($c->phone);

        $p = $this->getServerPackage($hp);

        $a = $this->di['server_account'];
        $a
            ->setClient($client)
            ->setPackage($p)
            ->setUsername($model->username)
            ->setReseller($model->reseller)
            ->setDomain($model->sld . $model->tld)
            ->setPassword($model->getTmpPass())
            ->setNs1($server->ns1)
            ->setNs2($server->ns2)
            ->setNs3($server->ns3)
            ->setNs4($server->ns4)
            ->setIp($model->ip);

        $orderService = $this->di['mod_service']('order');
        $order = $orderService->getServiceOrder($model);
        if($order instanceof \Model_ClientOrder ) {
            $adapter = $this->getServerManagerWithLog($server, $order);
        } else {
            $adapter = $this->getServerManager($server);
        }
        return array($adapter, $a);
    }

    public function toApiArray(\Model_ServiceHosting $model, $deep = false, $identity = null)
    {
        $serviceHostingServerModel = $this->di['db']->load('ServiceHostingServer', $model->service_hosting_server_id);
        $serviceHostingHpModel = $this->di['db']->load('ServiceHostingHp', $model->service_hosting_hp_id);
        $server = $this->toHostingServerApiArray($serviceHostingServerModel, $deep, $identity);
        $hp = $this->toHostingHpApiArray($serviceHostingHpModel, $deep, $identity);

        return array(
            'ip'            =>  $model->ip,
            'sld'           =>  $model->sld,
            'tld'           =>  $model->tld,
            'domain'        =>  $model->sld.$model->tld,
            'username'      =>  $model->username,
            'reseller'      =>  $model->reseller,
            'server'        =>  $server,
            'hosting_plan'  =>  $hp,
            'domain_order_id'  =>  $this->_getDomainOrderId($model),
        );
    }

    public function toHostingServerApiArray(\Model_ServiceHostingServer $model, $deep = false, $identity = null)
    {
        list($cpanel_url, $whm_url) = $this->getMangerUrls($model);
        $result = array(
            'name'                  =>  $model->name,
            'hostname'              =>  $model->hostname,
            'ip'                    =>  $model->ip,
            'ns1'                   =>  $model->ns1,
            'ns2'                   =>  $model->ns2,
            'ns3'                   =>  $model->ns3,
            'ns4'                   =>  $model->ns4,
            'cpanel_url'            =>  $cpanel_url,
            'reseller_cpanel_url'   =>  $whm_url,
        );

        if($identity instanceof \Model_Admin) {
            $result['id'] = $model->id;
            $result['active'] = $model->active;
            $result['secure'] = $model->secure;
            $result['assigned_ips'] = json_decode($model->assigned_ips, 1);
            $result['status_url'] = $model->status_url;
            $result['max_accounts'] = $model->max_accounts;
            $result['manager'] = $model->manager;
            $result['username'] = $model->username;
            $result['password'] = $model->password;
            $result['accesshash'] = $model->accesshash;
            $result['port'] = $model->port;
            $result['created_at'] = $model->created_at;
            $result['updated_at'] = $model->updated_at;
        }

        return $result;
    }

    private function _getDomainTuple($data)
    {
        if(!isset($data['domain'])) {
            throw new \Box_Exception('Hosting product must have domain configuration');
        }

        if(!isset($data['domain']['action'])) {
            throw new \Box_Exception('Domain action is missing');
        }

        if($data['domain']['action'] == 'owndomain') {
            $sld = $data['domain']['owndomain_sld'];
            $tld = $data['domain']['owndomain_tld'];
        }

        if($data['domain']['action'] == 'register') {
            if(!isset($data['domain']['register_sld'])) {
                throw new \Box_Exception('Hosting product must have defined register_sld parameter');
            }
            if(!isset($data['domain']['register_tld'])) {
                throw new \Box_Exception('Hosting product must have defined register_tld parameter');
            }
            $sld = $data['domain']['register_sld'];
            $tld = $data['domain']['register_tld'];
        }

        if($data['domain']['action'] == 'transfer') {
            if(!isset($data['domain']['transfer_sld'])) {
                throw new \Box_Exception('Hosting product must have defined transfer_sld parameter');
            }
            if(!isset($data['domain']['transfer_tld'])) {
                throw new \Box_Exception('Hosting product must have defined transfer_tld parameter');
            }
            $sld = $data['domain']['transfer_sld'];
            $tld = $data['domain']['transfer_tld'];
        }

        return array($sld, $tld);
    }

    public function update(\Model_ServiceHosting $model, array $data)
    {
        if(isset($data['username']) && !empty($data['username'])) {
            $model->username = $data['username'];
        }

        if(isset($data['ip']) && !empty($data['ip'])) {
            $model->ip = $data['ip'];
        }

        $model->updated_at = date('c');
        $this->di['db']->store($model);

        $this->di['logger']->info('Updated hosting account %s without sending actions to server', $model->id);
        return true;
    }


    public function getServerManagers()
    {
        $d = array();
        foreach($this->_getServerManagers() as $p) {
            $d[$p] = $this->getServerManagerConfig($p);
        }
        return $d;
    }

    private function _getServerManagers()
    {
        $dir = BB_PATH_LIBRARY . '/Server/Manager';
        $files = array();
        $directory = opendir($dir);
        while($item = readdir($directory)){
            if(($item != ".") && ($item != "..") && ($item != ".svn") ){
                $files[] = pathinfo($item, PATHINFO_FILENAME);
            }
        }
        sort($files);
        return $files;
    }

    public function getServerManagerConfig($manager)
    {
        $filename = BB_PATH_LIBRARY . '/Server/Manager/'.$manager.'.php';
        if(!file_exists($filename)) {
            return array();
        }

        $classname = 'Server_Manager_'.$manager;
        $method = 'getForm';
        if(!is_callable($classname.'::'.$method)) {
            return array();
        }

        return call_user_func(array($classname, $method));
    }

    public function getServerPairs()
    {
        $sql = 'SELECT id, name
                FROM service_hosting_server
                ORDER BY id ASC';
        $rows = $this->di['db']->getAll($sql);

        $result = array();
        foreach ($rows as $record) {
            $result[ $record['id'] ] = $record['name'];
        }
        return $result;
    }

    public function getServersSearchQuery($data)
    {
        $sql = 'SELECT *
                FROM service_hosting_server
                order by id ASC';
        return array($sql, array());
    }

    public function createServer($name, $ip, $manager, $extras)
    {
        $model = $this->di['db']->dispense('ServiceHostingServer');
        $model->name = $name;
        $model->ip = $ip;

        $model->hostname = isset($extras['hostname']) ? $extras['hostname'] : NULL;
        $model->assigned_ips = isset($extras['assigned_ips']) ? $extras['assigned_ips'] : NULL;
        $model->active = isset($extras['active']) ? $extras['active'] : 1;

        $model->status_url = isset($extras['status_url']) ? $extras['status_url'] : NULL;
        $model->max_accounts = isset($extras['max_accounts']) ? $extras['max_accounts'] : NULL;

        $model->ns1 = isset($extras['ns1']) ? $extras['ns1'] : NULL;
        $model->ns2 = isset($extras['ns2']) ? $extras['ns2'] : NULL;
        $model->ns3 = isset($extras['ns3']) ? $extras['ns3'] : NULL;
        $model->ns4 = isset($extras['ns4']) ? $extras['ns4'] : NULL;

        $model->manager = $manager;
        $model->username = isset($extras['username']) ? $extras['username'] : NULL;
        $model->password = isset($extras['password']) ? $extras['password'] : NULL;
        $model->accesshash = isset($extras['accesshash']) ? $extras['accesshash'] : NULL;
        $model->port = isset($extras['port']) ? $extras['port'] : NULL;
        $model->secure = isset($extras['secure']) ? $extras['secure'] : 0;

        $model->created_at = date('c');
        $model->updated_at = date('c');
        $newId = $this->di['db']->store($model);

        $this->di['logger']->info('Added new hosting server %s', $newId);

        return $newId;
    }

    public function deleteServer(\Model_ServiceHostingServer $model)
    {
        $id = $model->id;
        $this->di['db']->trash($model);
        $this->di['logger']->info('Deleted hosting server %s', $id);
        return true;
    }

    public function updateServer(\Model_ServiceHostingServer $model, array $data){

        if(isset($data['name'])) {
            $model->name = $data['name'];
        }

        if(isset($data['ip'])) {
            $model->ip = $data['ip'];
        }

        if(isset($data['hostname'])) {
            $model->hostname = $data['hostname'];
        }

        if(isset($data['assigned_ips'])) {
            $fn = create_function('&$val', '$val = trim($val);');
            $array = explode(PHP_EOL, $data['assigned_ips']);
            array_walk($array, $fn);
            $array = array_diff($array, array(''));
            $model->assigned_ips = json_encode($array);
        }

        if(isset($data['active'])) {
            $model->active = $data['active'];
        }

        if(isset($data['status_url'])) {
            $model->status_url = $data['status_url'];
        }

        if(isset($data['max_accounts'])) {
            $model->max_accounts = $data['max_accounts'];
        }

        if(isset($data['ns1'])) {
            $model->ns1 = $data['ns1'];
        }
        if(isset($data['ns2'])) {
            $model->ns2 = $data['ns2'];
        }
        if(isset($data['ns3'])) {
            $model->ns3 = $data['ns3'];
        }
        if(isset($data['ns4'])) {
            $model->ns4 = $data['ns4'];
        }

        if(isset($data['manager'])) {
            $model->manager = $data['manager'];
        }

        if(isset($data['username'])) {
            $model->username = $data['username'];
        }

        if(isset($data['password'])) {
            $model->password = $data['password'];
        }

        if(isset($data['accesshash'])) {
            $model->accesshash = $data['accesshash'];
        }

        if(isset($data['port'])) {
            $model->port = $data['port'];
        }

        if(isset($data['secure'])) {
            $model->secure = $data['secure'];
        }

        $model->updated_at = date('c');
        $this->di['db']->store($model);

        $this->di['logger']->info('Update hosting server %s', $model->id);
        return true;
    }

    public function getServerManager(\Model_ServiceHostingServer $model)
    {
        if(empty($model->manager)) {
            throw new \Box_Exception('Invalid server manager. Server was not configured properly.', null, 654);
        }

        $config = array();
        $config['ip'] = $model->ip;
        $config['host'] = $model->hostname;
        $config['port'] = $model->port;
        $config['secure'] = $model->secure;
        $config['username'] = $model->username;
        $config['password'] = $model->password;
        $config['accesshash'] = $model->accesshash;

        $manager = $this->di['server_manager']($model->manager, $config);

        if(!$manager instanceof \Server_Manager) {
            throw new \Box_Exception('Server manager :adapter is not valid', array(':adapter'=>$model->manager));
        }

        return $manager;
    }

    public function testConnection(\Model_ServiceHostingServer $model)
    {
        $m = $this->getServerManager($model);
        return $m->testConnection();
    }


    public function getHpPairs()
    {
        $sql = 'SELECT id, name
                FROM service_hosting_hp';
        $rows = $this->di['db']->getAll($sql);
        $result = array();
        foreach ($rows as $record) {
            $result[ $record['id'] ] = $record['name'];
        }
        return $result;
    }

    public function getHpSearchQuery($data)
    {
        $sql = 'SELECT *
                FROM service_hosting_hp
                ORDER BY id asc';
        return array($sql, array());
    }

    public function deleteHp(\Model_ServiceHostingHp $model)
    {
        $id = $model->id;
        $serviceHosting = $this->di['db']->findOne('ServiceHosting', 'service_hosting_hp_id = ?', array($model->id));
        if($serviceHosting) {
            throw new \Box_Exception('Can not remove hosting plan which has active accounts');
        }
        $this->di['db']->trash($model);
        $this->di['logger']->info('Deleted hosting plan %s', $id);
        return true;
    }

    public function toHostingHpApiArray(\Model_ServiceHostingHp $model, $deep = false, $identity = null)
    {
        $result = array(
            'id'         =>  $model->id,

            'name'       =>  $model->name,
            'bandwidth'  =>  $model->bandwidth,
            'quota'      =>  $model->quota,

            'max_ftp'    =>  $model->max_ftp,
            'max_sql'    =>  $model->max_sql,
            'max_pop'    =>  $model->max_pop,
            'max_sub'    =>  $model->max_sub,
            'max_park'   =>  $model->max_park,
            'max_addon'  =>  $model->max_addon,
            'config'     =>  json_decode($model->config, 1),

            'created_at'     =>  $model->created_at,
            'updated_at'     =>  $model->updated_at,
        );
        return $result;
    }

    public function updateHp(\Model_ServiceHostingHp $model, $data)
    {
        if(isset($data['name'])) {
            $model->name = $data['name'];
        }
        if(isset($data['bandwidth'])) {
            $model->bandwidth = $data['bandwidth'];
        }
        if(isset($data['quota'])) {
            $model->quota = $data['quota'];
        }
        if(isset($data['max_addon'])) {
            $model->max_addon = $data['max_addon'];
        }
        if(isset($data['max_ftp'])) {
            $model->max_ftp = $data['max_ftp'];
        }
        if(isset($data['max_sql'])) {
            $model->max_sql = $data['max_sql'];
        }
        if(isset($data['max_pop'])) {
            $model->max_pop = $data['max_pop'];
        }
        if(isset($data['max_sub'])) {
            $model->max_sub = $data['max_sub'];
        }
        if(isset($data['max_park'])) {
            $model->max_park = $data['max_park'];
        }

        /* add new config value to hosting plan */
        $config = json_decode($model->config, 1);

        if(isset($data['config']) && is_array($data['config'])) {
            foreach($data['config'] as $key=>$val) {
                if(isset($config[$key])) {
                    $config[$key] = $val;
                }
                if(isset($config[$key]) && empty ($val)) {
                    unset ($config[$key]);
                }
            }
        }

        if(isset($data['new_config_name']) &&
            isset($data['new_config_value']) &&
            !empty($data['new_config_name']) &&
            !empty($data['new_config_value'])) {

            $config[$data['new_config_name']] = $data['new_config_value'];
        }

        $model->config = json_encode($config);
        $model->updated_at = date('c');
        $this->di['db']->store($model);

        $this->di['logger']->info('Updated hosting plan %s', $model->id);
        return true;
    }

    public function createHp($name, $data)
    {
        $model = $this->di['db']->dispense('ServiceHostingHp');
        $model->name = $name;

        $model->bandwidth = isset($data['bandwidth']) ? $data['bandwidth'] : 1024 * 1024;
        $model->quota = isset($data['quota']) ? $data['quota'] : 1024 * 1024;

        $model->max_addon = isset($data['max_addon']) ? $data['max_addon'] : 1;
        $model->max_park = isset($data['max_park']) ? $data['max_park'] : 1;
        $model->max_sub = isset($data['max_sub']) ? $data['max_sub'] : 1;
        $model->max_pop = isset($data['max_pop']) ? $data['max_pop'] : 1;
        $model->max_sql = isset($data['max_sql']) ? $data['max_sql'] : 1;
        $model->max_ftp = isset($data['max_ftp']) ? $data['max_ftp'] : 1;

        $model->created_at = date('c');
        $model->updated_at = date('c');
        $newId = $this->di['db']->store($model);

        $this->di['logger']->info('Added new hosting plan %s', $newId);
        return $newId;
    }

    public function getServerPackage(\Model_ServiceHostingHp $model)
    {
        $config = json_decode($model->config, 1);
        if (!is_array($config)){
            $config = array();
        }

        $p = $this->di['server_package'];
        $p
            ->setCustomValues($config)
            ->setMaxFtp($model->max_ftp)
            ->setMaxSql($model->max_sql)
            ->setMaxPop($model->max_pop)
            ->setMaxSubdomains($model->max_sub)
            ->setMaxParkedDomains($model->max_park)
            ->setMaxDomains($model->max_addon)
            ->setBandwidth($model->bandwidth)
            ->setQuota($model->quota)
            ->setName($model->name);

        return $p;
    }

    public function getServerManagerWithLog(\Model_ServiceHostingServer $model, \Model_ClientOrder $order)
    {
        $manager = $this->getServerManager($model);

        $order_service = $this->di['mod_service']('order');
        $log = $order_service->getLogger($order);
        $manager->setLog($log);
//        $manager->setDi($this->di);

        return $manager;
    }

    public function getMangerUrls(\Model_ServiceHostingServer $model)
    {
        try {
            $m = $this->getServerManager($model);
            return array($m->getLoginUrl(), $m->getResellerLoginUrl());
        } catch(\Exception $e) {
            error_log('Error while retrieving cPanel url: '. $e->getMessage());
        }
        return array(false, false);
    }

    public function prependOrderConfig(\Model_Product $product, array $data)
    {
        list($sld, $tld) = $this->_getDomainTuple($data);
        $data['sld'] = $sld;
        $data['tld'] = $tld;
        $c = $this->di['tools']->decodeJ($product->config);
        return array_merge($c, $data);
    }

    public function getDomainProductFromConfig(\Model_Product $product, array &$data)
    {
        $data = $this->prependOrderConfig($product, $data);
        $product->getService()->validateOrderData($data);
        $c = $this->di['tools']->decodeJ($product->config);

        $dc = $data['domain'];
        $action = $dc['action'];

        $drepo = $this->di['mod_service']('servicedomain');
        $drepo->validateOrderData($dc);
        if($action == 'owndomain') {
            return false;
        }

        if(isset($c['free_domain']) && $c['free_domain']) {
            $dc['free_domain'] = true;
        }

        if(isset($c['free_transfer']) && $c['free_transfer']) {
            $dc['free_transfer'] = true;
        }

        $table = $this->di['mod_service']('product');
        $d = $table->getMainDomainProduct();
        if(!$d instanceof \Model_Product) {
            throw new \Box_Exception('Could not find main domain product');
        }
        return array('product'=>$d, 'config'=> $dc);
    }
}