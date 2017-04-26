<?php
class Wuunder_WuunderConnector_Helper_Data extends Mage_Core_Helper_Abstract
{

    const WUUNERCONNECTOR_LOG_FILE = 'wuunder.log';
    const XPATH_DEBUG_MODE = 'wuunderconnector/connect/debug_mode';
    const MIN_PHP_VERSION = '5.3.0';
    public $tblPrfx;

    function __construct()
    {
        $this->tblPrfx = (string)Mage::getConfig()->getTablePrefix();
    }

    public function log($message, $level = null, $file = null, $forced = false, $isError = false)
    {

        if ($isError === true && !$this->isExceptionLoggingEnabled() && !$forced) {
            return $this;
        } elseif ($isError !== true && !$this->isLoggingEnabled() && !$forced) {
            return $this;
        }

        if (is_null($level)) {
            $level = Zend_Log::DEBUG;
        }

        if (is_null($file)) {
            $file = static::WUUNERCONNECTOR_LOG_FILE;
        }

        Mage::log($message, $level, $file, $forced);

        return $this;
    }

    public function isLoggingEnabled()
    {
        if (version_compare(phpversion(), self::MIN_PHP_VERSION, '<')) {
            return false;
        }

        $debugMode = $this->getDebugMode();
        if ($debugMode > 0) {
            return true;
        }

        return false;
    }

    public function getDebugMode()
    {
        if (Mage::registry('wuunderconnector_debug_mode') !== null) {
            return Mage::registry('wuunderconnector_debug_mode');
        }

        $debugMode = (int) Mage::getStoreConfig(self::XPATH_DEBUG_MODE, Mage_Core_Model_App::ADMIN_STORE_ID);
        Mage::register('wuunderconnector_debug_mode', $debugMode);
        return $debugMode;
    }


    public function getWuunderOptions()
    {
        return array(
            'header' => 'Wuunder Options',
            'index' => 'wuunder_options',
            'type' => 'text',
            'width' => '120px',
            'renderer' => 'Wuunder_WuunderConnector_Block_Adminhtml_Order_Renderer_WuunderIcons',
            'filter'    => false,
        );
    }

    /**
     * getLabelType
     *
     * @param $orderId
     * @return array
     */
    public function getShipmentInfo($orderId)
    {

        $mageDb     = Mage::getSingleton('core/resource')->getConnection('core_read');
        $sql        = "SELECT * FROM ".$this->tblPrfx."wuunder_shipments WHERE order_id = ?";
        $results    = $mageDb->query($sql, $orderId);
        $entity     = $results->fetch();

        $returnArray = array();

        // wuunder_shipment AND label_id exist, so create return label
        if ($entity) {
            $returnArray = array(
                'shipment_id' => $entity['shipment_id'],
                'label_type' => 'retour',
                'label_id' => $entity['label_id'],
                'retour_id' => $entity['retour_id'],
                'package_type' => $entity['type'],
                'reference' => $entity['description'],
                'wuunder_length' => $entity['length'],
                'wuunder_width' => $entity['width'],
                'wuunder_height' => $entity['height'],
                'wuunder_weight' => $entity['weight'],
                'phone_number' => $entity['phone_number'],
                'personal_message' => $entity['personal_message'],
                'retour_message' => $entity['retour_message']
            );
        } else {
            $returnArray = array(
                'shipment_id' => '',
                'label_type' => 'shipping',
                'label_id' => '',
                'retour_id' => '',
                'package_type' => '',
                'reference' => '',
                'wuunder_length' => '',
                'wuunder_width' => '',
                'wuunder_height' => '',
                'wuunder_weight' => '',
                'phone_number' => '',
                'personal_message' => '',
                'retour_message' => ''
            );
        }

        // If shipment already exists, but no label_id was found (because of an error)
        // Create shipping label again
        if ($entity['label_id'] == '') {
            $returnArray['label_type'] = 'shipping';
        }

        return $returnArray;
    }

    public function getInfoFromOrder($orderId)
    {
        $weightUnit     = Mage::getStoreConfig('wuunderconnector/magentoconfig/weight_units');
        // Get Magento order
        $orderInfo      = Mage::getModel('sales/order')->load($orderId);
        $totalWeight    = 0;
        $orderLines     = array();
        $prodNames      = '';
        // Get total weight from ordered items
        foreach ($orderInfo->getAllItems() AS $orderedItem) {
            // Calculate weight
            if ($orderedItem->getWeight() > 0) {
                if ($weightUnit == 'kg') {
                    $productWeight = round($orderedItem->getQtyOrdered() * $orderedItem->getWeight() * 1000);
                } else {
                    $productWeight = round($orderedItem->getQtyOrdered() * $orderedItem->getWeight());
                }

                $totalWeight += $productWeight;
            }
            $prodNames .= $orderedItem->getName().',';
            array_push($orderLines, array('name' => $orderedItem->getName(), 'weight' => $orderedItem->getWeight(), 'qty' => $orderedItem->getQtyOrdered()));
        }
        if(strlen($prodNames) > 0) {
            $productNames = substr($prodNames, 0, -1); // haalt de laatste komma er af
        } else {
            $productNames = '';
        }
        return array('product_names' => $productNames, 'order_lines' => $orderLines, 'total_weight' => $totalWeight);
    }

    public function getWebshopAddress()
    {

        return array(
            'company'       => Mage::getStoreConfig('wuunderconnector/connect/company'),
            'firstname'     => Mage::getStoreConfig('wuunderconnector/connect/firstname'),
            'lastname'      => Mage::getStoreConfig('wuunderconnector/connect/lastname'),
            'streetname'    => Mage::getStoreConfig('wuunderconnector/connect/streetname'),
            'housenumber'   => Mage::getStoreConfig('wuunderconnector/connect/housenumber'),
            'postcode'      => Mage::getStoreConfig('wuunderconnector/connect/zipcode'),
            'city'          => Mage::getStoreConfig('wuunderconnector/connect/city'),
            'email'         => Mage::getStoreConfig('wuunderconnector/connect/email'),
            'phone'         => Mage::getStoreConfig('wuunderconnector/connect/phone'),
            'country'       => Mage::getStoreConfig('wuunderconnector/connect/country'));
    }

    /**
     * @param $infoArray
     * @return array
     */
    public function processLabelInfo($infoArray)
    {

        // Check if type = 'retour' and if retour_id already exists
        // If so, show warning, shipping label AND retour already generated
        if ($infoArray['label_type'] == 'retour') {

            // we gaan iets terug sturen
            if (isset($infoArray['retour_id']) && $infoArray['retour_id'] != '') {
                return array('error' => true, 'message' => 'Retour ID already available, no retour label generated');
            }
        }

        // Create or update wuunder_shipment
        $wuunderShipmentSaved = $this->saveWuunderShipment($infoArray);
        if (!$wuunderShipmentSaved) {
            return array('error' => true, 'message' => 'Unable to create / update wuunder_shipment for order '.$infoArray['order_id']);
        }

        // Fetch order
        $order      = Mage::getModel('sales/order')->load($infoArray['order_id']);
        $storeId    = $order->getStoreId();

        // Get configuration
        $testMode   = Mage::getStoreConfig('wuunderconnector/connect/testmode', $storeId);

        if ($testMode == 1) {
            $apiUrl = 'https://api-staging.wuunder.co/api/shipments';
            $apiKey = Mage::getStoreConfig('wuunderconnector/connect/api_key_test', $storeId);
        } else {
            $apiUrl = 'https://api.wuunder.co/api/shipments';
            $apiKey = Mage::getStoreConfig('wuunderconnector/connect/api_key_live', $storeId);
        }

        // Combine wuunder info and order data
        $wuunderData = $this->buildWuunderData($infoArray, $order);

        // Encode variables
        $json = json_encode($wuunderData);

        // Setup API connection
        $cc = curl_init($apiUrl);
        $this->log('API connection established');

        curl_setopt($cc, CURLOPT_HTTPHEADER, array('Authorization: Bearer ' . $apiKey, 'Content-type: application/json'));
        curl_setopt($cc, CURLOPT_POST, 1);
        curl_setopt($cc, CURLOPT_POSTFIELDS, $json);
        curl_setopt($cc, CURLOPT_RETURNTRANSFER, true);

        // Don't log base64 image string
        $wuunderData['picture'] = 'base64 string removed';
        Mage::helper('wuunderconnector')->log('API request string: '.json_encode($wuunderData));

        // Execute the cURL, fetch the XML
        $result = curl_exec($cc);

        // Close connection
        curl_close($cc);

        Mage::helper('wuunderconnector')->log('API response string: '.$result);

        // Decode API result
        $result = json_decode($result);

        // Check for API errors
        if (isset($result->error)) {
            return $this->showWuunderAPIError($result->error);
        }
        if (isset($result->errors)) {
            return $this->showWuunderAPIError($result->errors);
        }

        $processDataSuccess = $this->processDataFromApi($result, $infoArray['label_type'], $infoArray['order_id']);
        if (!$processDataSuccess) {
            return array('error' => true, 'message' => 'Er ging iets fout bij het updaten van tabel wuunder_shipments');
        } else {
            return array('error' => false, 'message' => 'Label met succes aangemaakt');
        }
    }

    public function processDataFromApi($wuunderApiResult, $labelType, $orderId)
    {
        // we slaan iets op dus we hebben core_write nodig
        $mageDbW    = Mage::getSingleton('core/resource')->getConnection('core_write');
        if ($labelType == 'retour') {
            $sqlUpdate = "UPDATE ".$this->tblPrfx."wuunder_shipments SET retour_id = ?, retour_date = now(), retour_url = ?, retour_tt_url = ? WHERE order_id = ?";
        } else {
            $sqlUpdate = "UPDATE ".$this->tblPrfx."wuunder_shipments SET label_id = ?, label_date = now(), label_url = ?, label_tt_url = ? WHERE order_id = ?";
        }
        try {
            $mageDbW->query($sqlUpdate, array($wuunderApiResult->id, $wuunderApiResult->label_url, $wuunderApiResult->track_and_trace_url, $orderId));
            return true;
        }  catch (Mage_Core_Exception $e) {
            $this->log('ERROR saveWuunderShipment : '.$e);
            return false;
        }
    }

    public function buildWuunderData($infoArray, $order)
    {

        $shippingAddress = $order->getShippingAddress();

        if ($shippingAddress->middlename != '') {
            $shippingLastname = $shippingAddress->middlename.' '.$shippingAddress->lastname;
        } else {
            $shippingLastname = $shippingAddress->lastname;
        }

        // Get full address, strip enters/newlines etc
        $addressLine = trim(preg_replace('/\s+/', ' ', $shippingAddress->street));

        // Splitt addres in 3 parts
        $addressParts = $this->addressSplitter($addressLine);
        $streetName = $addressParts['streetName'];
        $houseNumber = $addressParts['houseNumber'].$addressParts['houseNumberSuffix'];

        $customerAdr = array(
            'business'      => $shippingAddress->company,
            'email_address' => $shippingAddress->email,
            'family_name'   => $shippingLastname,
            'given_name'    => $shippingAddress->firstname,
            'locality'      => $shippingAddress->city,
            'phone_number'  => $infoArray['phone_number'],
            'street_address' => $streetName,
            'house_number'  => $houseNumber,
            'zip_code'      => $shippingAddress->postcode,
            'country'       => $shippingAddress->country_id
        );

        $webshopAdr = array(
            'business'      => Mage::getStoreConfig('wuunderconnector/connect/company'),
            'email_address' => Mage::getStoreConfig('wuunderconnector/connect/email'),
            'family_name'   => Mage::getStoreConfig('wuunderconnector/connect/lastname'),
            'given_name'    => Mage::getStoreConfig('wuunderconnector/connect/firstname'),
            'locality'      => Mage::getStoreConfig('wuunderconnector/connect/city'),
            'phone_number'  => Mage::getStoreConfig('wuunderconnector/connect/phone'),
            'street_address' => Mage::getStoreConfig('wuunderconnector/connect/streetname'),
            'house_number'  => Mage::getStoreConfig('wuunderconnector/connect/housenumber'),
            'zip_code'      => Mage::getStoreConfig('wuunderconnector/connect/zipcode'),
            'country'       => Mage::getStoreConfig('wuunderconnector/connect/country')
        );

        // als retour dan wordt het eerste adres die van de klant en anders andersom
        if ($infoArray['label_type'] == 'retour') {
            $senderAddress   = $webshopAdr;
            $receiverAddress  = $customerAdr;
        } else {
            $senderAddress   = $customerAdr;
            $receiverAddress  = $webshopAdr;
        }

        $orderAmountExclVat = ($order->getGrandTotal() - $order->getTaxAmount());

        // Load product image for first ordered item
        $image = '';
        $orderedItems = $order->getAllVisibleItems();
        if (count($orderedItems) > 0) {
            foreach ($orderedItems AS $orderedItem) {
                $_product = Mage::getModel('catalog/product')->load($orderedItem->getProductId());
                $base64Image = base64_encode(file_get_contents(Mage::helper('catalog/image')->init($_product,'image')));
                if ($base64Image != '') {
                    // Break after first image
                    $image = $base64Image;
                    break;
                }
            }
        }

        return array(
            'description'           => $infoArray['reference'],
            'personal_message'      => $infoArray['personal_message'],
            'picture'               => $image,
            'customer_reference'    => $order->getIncrementId(),
            'value'                 => $orderAmountExclVat,
            'kind'                  => $infoArray['packing_type'],
            'length'                => $infoArray['length'],
            'width'                 => $infoArray['width'],
            'height'                => $infoArray['height'],
            'weight'                => $infoArray['weight'],
            'delivery_address'      => $senderAddress,
            'pickup_address'        => $receiverAddress
        );
    }

    public function saveWuunderShipment($infoArray)
    {

        $mageDbW = Mage::getSingleton('core/resource')->getConnection('core_write');

        // Check if wuunder_shipment already exists
        $sqlQuery = "SELECT `shipment_id` FROM `".$this->tblPrfx."wuunder_shipments` WHERE `order_id` = ".intval($infoArray['order_id']).' LIMIT 1';
        $shipmentId = $mageDbW->fetchOne($sqlQuery);

        if ($shipmentId > 0) {

            $messageField = ($infoArray['label_type'] == 'retour') ? 'retour_message' : 'personal_message';

            $sqlQuery = "UPDATE `".$this->tblPrfx."wuunder_shipments` SET
                        `order_id`          = ?,
                        `description`       = ?,
                        `type`              = ?,
                        `length`            = ?,
                        `width`             = ?,
                        `height`            = ?,
                        `weight`            = ?,
                        `phone_number`      = ?,
                        `".$messageField."`  = ?
                    WHERE
                        `shipment_id`  = ?";

            $sqlValues = array($infoArray['order_id'], $infoArray['reference'], $infoArray['packing_type'], $infoArray['length'], $infoArray['width'], $infoArray['height'], $infoArray['weight'], $infoArray['phone_number'], $infoArray['personal_message'], $shipmentId);

        } else {

            $sqlQuery  = "INSERT INTO `".$this->tblPrfx."wuunder_shipments` SET
                        `order_id`          = ?,
                        `description`       = ?,
                        `type`              = ?,
                        `length`            = ?,
                        `width`             = ?,
                        `height`            = ?,
                        `weight`            = ?,
                        `phone_number`      = ?,
                        `personal_message`  = ?";

            $sqlValues = array($infoArray['order_id'], $infoArray['reference'], $infoArray['packing_type'], $infoArray['length'], $infoArray['width'], $infoArray['height'], $infoArray['weight'], $infoArray['phone_number'], $infoArray['personal_message']);
        }

        try {

            $results = $mageDbW->query($sqlQuery, $sqlValues);
            return true;

        }  catch (Mage_Core_Exception $e) {

            $this->log('ERROR saveWuunderShipment : '.$e);
            return false;
        }
    }

    public function addressSplitter($address, $address2 = null, $address3 = null) {

        if (!isset($address)) {
            return false;
        }

        if (isset($address2) && $address2 != '' && isset($address3) && $address3 != '') {

            $result['streetName'] = $address;
            $result['houseNumber'] = $address2;
            $result['houseNumberSuffix'] = $address3;

        } else if (isset($address2) && $address2 != '') {

            $result['streetName'] = $address;

            // Pregmatch pattern, dutch addresses
            $pattern = '#^([0-9]{1,5})([a-z0-9 \-/]{0,})$#i';

            preg_match($pattern, $address2, $houseNumbers);

            $result['houseNumber'] = $houseNumbers[1];
            $result['houseNumberSuffix'] = (isset($houseNumbers[2])) ? $houseNumbers[2] : '';

        } else {

            // Pregmatch pattern, dutch addresses
            $pattern = '#^([a-z0-9 [:punct:]\']*) ([0-9]{1,5})([a-z0-9 \-/]{0,})$#i';

            preg_match($pattern, $address, $addressParts);

            $result['streetName'] = $addressParts[1];
            $result['houseNumber'] = $addressParts[2];
            $result['houseNumberSuffix'] = (isset($addressParts[3])) ? $addressParts[3] : '';
        }

        //$this->log('After split => 1) '.$result['streetName'].' / 2) '.$result['houseNumber'].' / 3) '.$result['houseNumberSuffix']);
        return $result;
    }


    public function showWuunderAPIError($errors)
    {

        // Log error
        $this->log($errors);

        $errorMessage = '';

        if (is_array($errors)) {
            if (count($errors) > 0) {
                foreach ($errors AS $error) {

                    $errorMessage.= 'API response error on field(s): '.$error->field;
                    foreach ($error->messages AS $message) {
                        $errorMessage.= ' - '.$message.'<br />';
                    }
                }

                return array('error' => true, 'message' => $errorMessage);
            }
        } else if (is_string($errors)) {

            // Show first 1000 characters
            //return array('error' => true, 'message' => 'API response error: '.substr($errors,0,1000));
            return array('error' => true, 'message' => 'API response error: '.$errors);
        }

        return array('error' => true, 'message' => 'Unknown error! Enable Wuunder logging and please check /var/log/wuunder.log for more information');
    }
}