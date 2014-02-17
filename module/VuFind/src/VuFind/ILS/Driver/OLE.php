<?php
/**
 * OLE ILS Driver
 *
 * PHP version 5
 *
 * Copyright (C) Villanova University 2013.
 *
 * This program is free software; you can redistribute it and/or modify
 * it under the terms of the GNU General Public License version 2,
 * as published by the Free Software Foundation.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with this program; if not, write to the Free Software
 * Foundation, Inc., 59 Temple Place, Suite 330, Boston, MA  02111-1307  USA
 *
 * @category VuFind2
 * @package  ILS_Drivers
 * @author   Demian Katz <demian.katz@villanova.edu>
 * @author   David Lacy <david.lacy@villanova.edu>
 * @license  http://opensource.org/licenses/gpl-2.0.php GNU General Public License
 * @link     http://vufind.org/wiki/vufind2:building_an_ils_driver Wiki
 */
namespace VuFind\ILS\Driver;
use File_MARC, PDO, PDOException, Exception,
    VuFind\Exception\ILS as ILSException,
	Zend\Http\Client,
	Zend\Http\Request;

/**
 * OLE ILS Driver
 *
 * @category VuFind2
 * @package  ILS_Drivers
 * @author   Demian Katz <demian.katz@villanova.edu>
 * @author   David Lacy <demian.katz@villanova.edu>
 * @license  http://opensource.org/licenses/gpl-2.0.php GNU General Public License
 * @link     http://vufind.org/wiki/vufind2:building_an_ils_driver Wiki
 */
class OLE extends AbstractBase implements \VuFindHttp\HttpServiceAwareInterface
{
    /**
     * HTTP service
     *
     * @var \VuFindHttp\HttpServiceInterface
     */
    protected $httpService = null;

    /**
     * Database connection
     *
     * @var PDO
     */
    protected $db;

    /**
     * Name of database
     *
     * @var string
     */
    protected $dbName;
    
    /**
     * Location of OLE's circ service
     *
     * @var string
     */
    protected $circService;
    
    /**
     * Location of OLE's docstore service
     *
     * @var string
     */
    protected $docService;

    /**
     * Set the HTTP service to be used for HTTP requests.
     *
     * @param HttpServiceInterface $service HTTP service
     *
     * @return void
     */
    public function setHttpService(\VuFindHttp\HttpServiceInterface $service)
    {
        $this->httpService = $service;
    }

	/**
     * Should we check renewal status before presenting a list of items or only
     * after user requests renewal?
     *
     * @var bool
     */
    protected $checkRenewalsUpFront;
	
	/* TODO Delete
	protected $record;
	*/
	
	/**
     * Default pickup location
     *
     * @var string
     */
    protected $defaultPickUpLocation;
	
    /**
     * Initialize the driver.
     *
     * Validate configuration and perform all resource-intensive tasks needed to
     * make the driver active.
     *
     * @throws ILSException
     * @return void
     */
    public function init()
    {
        if (empty($this->config)) {
            throw new ILSException('Configuration needs to be set.');
        }
        
		$this->checkRenewalsUpFront
            = isset($this->config['Renewals']['checkUpFront'])
            ? $this->config['Renewals']['checkUpFront'] : true;
		
		$this->defaultPickUpLocation
            = $this->config['Holds']['defaultPickUpLocation'];

        // Define Database Name
        $this->dbName = $this->config['Catalog']['database'];
        
        // Define OLE's circualtion service
        $this->circService = $this->config['Catalog']['circulation_service'];
        
        // Define OLE's docstore service
        $this->docService = $this->config['Catalog']['docstore_service'];

        $tns = '(DESCRIPTION=' .
                 '(ADDRESS_LIST=' .
                   '(ADDRESS=' .
                     '(PROTOCOL=TCP)' .
                     '(HOST=' . $this->config['Catalog']['host'] . ')' .
                     '(PORT=' . $this->config['Catalog']['port'] . ')' .
                   ')' .
                 ')' .
               ')';
        try {
            $this->db = new PDO(
                "mysql:host=localhost;port=3306;dbname=ole",
                $this->config['Catalog']['user'],
                $this->config['Catalog']['password']
            );
            
            $this->db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        } catch (PDOException $e) {
            throw $e;
        }
        
    }

	/**
     * Public Function which retrieves renew, hold and cancel settings from the
     * driver ini file.
     *
     * @param string $function The name of the feature to be checked
     *
     * @return array An array with key-value pairs.
     */
    public function getConfig($function)
    {
        if (isset($this->config[$function]) ) {
            $functionConfig = $this->config[$function];
        } else {
            $functionConfig = false;
        }
        return $functionConfig;
    }
	
    /**
     * Patron Login
     *
     * This is responsible for authenticating a patron against the catalog.
     *
     * @param string $barcode The patron barcode
     * @param string $login   The patron's last name or PIN (depending on config)
     *
     * @throws ILSException
     * @return mixed          Associative array of patron info on successful login,
     * null on unsuccessful login.
     */
    public function patronLogin($barcode, $login)
    {
        // Load the field used for verifying the login from the config file, and
        // make sure there's nothing crazy in there:
        $login_field = isset($this->config['Catalog']['login_field'])
            ? $this->config['Catalog']['login_field'] : 'LAST_NAME';
        $login_field = preg_replace('/[^\w]/', '', $login_field);

        $sql = "SELECT * " .
               "FROM $this->dbName.ole_ptrn_t, $this->dbName.krim_entity_nm_t " .
               "where ole_ptrn_t.OLE_PTRN_ID=krim_entity_nm_t.ENTITY_ID AND " .
               "lower(krim_entity_nm_t.{$login_field}) = :login AND " .
               "lower(ole_ptrn_t.BARCODE) = :barcode";

        try {
            $sqlStmt = $this->db->prepare($sql);
            $sqlStmt->bindParam(
                ':login', strtolower(utf8_decode($login)), PDO::PARAM_STR
            );
            $sqlStmt->bindParam(
                ':barcode', strtolower(utf8_decode($barcode)), PDO::PARAM_STR
            );

            $sqlStmt->execute();
            $row = $sqlStmt->fetch(PDO::FETCH_ASSOC);
            if (isset($row['OLE_PTRN_ID']) && ($row['OLE_PTRN_ID'] != '')) {
                return array(
                    'id' => utf8_encode($row['OLE_PTRN_ID']),
                    'firstname' => utf8_encode($row['FIRST_NM']),
                    'lastname' => utf8_encode($row['LAST_NM']),
                    'cat_username' => $barcode,
                    'cat_password' => $login,
                    'email' => null,
                    'major' => null,
                    'college' => null);
            } else {
                return null;
            }
        } catch (PDOException $e) {
            throw new ILSException($e->getMessage());
        }
    }

    /**
     * Get Patron Profile
     *
     * This is responsible for retrieving the profile for a specific patron.
     *
     * @param array $patron The patron array
     *
     * @throws ILSException
     * @return array        Array of the patron's profile data on success.
     */
    public function getMyProfile($patron)
    {

        try {
			/* TODO: Use the Zend HTTP service instead of file_get_contents */
            $xml = simplexml_load_string(file_get_contents($this->circService . '?service=lookupUser&patronId=' . $patron['id'] . '&operatorId=dev2'));

            $patron['email'] = '';
            $patron['address1'] = '';
            $patron['address2'] = null;
            $patron['zip'] = '';
            $patron['phone'] = '';
            $patron['group'] = '';
			
			if (!empty($xml->patronName->firstName)) {
				$patron['firstname'] = utf8_encode($xml->patronName->firstName);
			}
			if (!empty($xml->patronName->lastName)) {
				$patron['lastname'] = utf8_encode($xml->patronName->lastName);
			}
			if (!empty($xml->patronEmail->emailAddress)) {
				$patron['email'] = utf8_encode($xml->patronEmail->emailAddress);
			}
			if (!empty($xml->patronAddress->line1)) {
				$patron['address1'] = utf8_encode($xml->patronAddress->line1);
			}
			if (!empty($xml->patronAddress->line2)) {
				$patron['address2'] = utf8_encode($xml->patronAddress->line2);
			}
			if (!empty($xml->patronAddress->postalCode)) {
				$patron['zip'] = utf8_encode($xml->patronAddress->postalCode);
			}
			if (!empty($xml->patronPhone->phoneNumber)) {
				$patron['phone'] = utf8_encode($xml->patronPhone->phoneNumber);
			}

            return (empty($patron) ? null : $patron);

        } catch (Exception $e) { //TODO: switch this to catch exception thrown by zend http service
            throw new ILSException($e->getMessage());
        }
    }

    /**
     * Get Patron Transactions
     *
     * This is responsible for retrieving all transactions (i.e. checked out items)
     * by a specific patron.
     *
     * @param array $patron The patron array from patronLogin
     *
     * @throws DateException - TODO
     * @throws ILSException
     * @return array        Array of the patron's transactions on success.
     */
    public function getMyTransactions($patron)
    {
        /*

        Checked out Items
        ----------------
        http://localhost:8080/olefs/circulation?service=getCheckedOutItems&patronId=10100055U&operatorId=dev2
        */
        $transList = array();

        try {
			/* TODO: use the zend http service */
			$xml = simplexml_load_string(file_get_contents($this->circService . '?service=getCheckedOutItems&patronId=' . $patron['id'] . '&operatorId=dev2'));
		
            $checkedOutItems = $xml->xpath('//checkOutItem');
            
            foreach($checkedOutItems as $item) {
                $processRow = $this->processMyTransactionsData($item, $patron);
                $transList[] = $processRow;
            }
            return $transList;
        } catch (Exception $e) { //TODO: switch this to catch exception thrown by zend http service
            throw new ILSException($e->getMessage());
        }
    }
	
    /**
     * Get Patron Fines
     *
     * This is responsible for retrieving all fines by a specific patron.
     *
     * @param array $patron The patron array from patronLogin
     *
     * @throws DateException - TODO
     * @throws ILSException
     * @return mixed        Array of the patron's fines on success.
     */
	 /* TODO: this hasn't been fully implemented yet */
	 
    public function getMyFines($patron)
    {
		/*
		http://localhost:8080/olefs/circulation?service=fine&patronId=10100055U&operatorId=dev2
		*/

        $fineList = array();

        try {
			/* TODO: use the zend http service */
			$xml = simplexml_load_string(file_get_contents($this->circService . '?service=fine&patronId=' . $patron['id'] . '&operatorId=API'));
		
            $fines = $xml->xpath('//fineItem');

            foreach($fines as $fine) {
				//var_dump($fine);
                $processRow = $this->processMyFinesData($fine, $patron);
				//var_dump($processRow);
                $fineList[] = $processRow;
            }

        } catch (Exception $e) { //TODO: switch this to catch exception thrown by zend http service
            throw new ILSException($e->getMessage());
        }

		return $fineList;

    }
	/**
     * Protected support method for getMyHolds.
     *
     * @param array $itemXml simplexml object of item data
     * @param array $patron array
	 *
     * @throws DateException
     * @return array Keyed data for display by template files
     */
    protected function processMyFinesData($itemXml, $patron = false)
    {

		$recordId = (string)$itemXml->catalogueId;
		
		$record = $this->getRecord($recordId);

        return array(
				 'amount' => (string)$itemXml->amount,
				 'fine' => '',
				 'balance' => (string)$itemXml->balance,
				 'createdate' => '',
				 'checkout' => '',
				 'duedate' => '',
				 'id' => $recordId
			 );
	}
	
    /**
     * Get Patron Holds
     *
     * This is responsible for retrieving all holds by a specific patron.
     *
     * @param array $patron The patron array from patronLogin
     *
     * @throws DateException - TODO
     * @throws ILSException
     * @return array        Array of the patron's holds on success.
     */
    public function getMyHolds($patron)
    {
        /*
        GET
        ---
        lample: http://localhost:8080/olefs/circulation?service=holds&patronId=20235562K&operatorId=API
        */

        $holdList = array();

        try {
			/* TODO: use the zend http service */
			$xml = simplexml_load_string(file_get_contents($this->circService . '?service=holds&patronId=' . $patron['id'] . '&operatorId=API'));
			
            $holdItems = $xml->xpath('//hold');
			$holdsList = array();
			
            foreach($holdItems as $item) {
                //var_dump($item);
                $processRow = $this->processMyHoldsData($item, $patron);
                //var_dump($processRow);
                $holdsList[] = $processRow;
            }
            return $holdsList;
        } catch (Exception $e) { //TODO: switch this to catch exception thrown by zend http service
            throw new ILSException($e->getMessage());
        }
    }

    /**
     * Protected support method for getMyHolds.
     *
     * @param array $itemXml simplexml object of item data
     * @param array $patron array
     *
     * @throws DateException - TODO
     * @return array Keyed data for display by template files
     */
    protected function processMyHoldsData($itemXml, $patron = false)
    {
        $availableDateTime = (string) $itemXml->availableDate;
        $available = ($availableDateTime <= date('Y-m-d')) ? true:false;

        return array(
            'id' => substr((string) $itemXml->catalogueId, strpos((string) $itemXml->catalogueId, '-')+1),
            'item_id' => (string) $itemXml->itemId,
            'type' => (string) $itemXml->requestType,
            'location' => '',
            'expire' => (string) $itemXml->expiryDate,
            'create' => (string) $itemXml->createDate,
            'position' => (string) $itemXml->priority,
            'available' => $available,
            'reqnum' => (string) $itemXml->requestId,
            'volume' => '',
            'publication_year' => '',
            'title' => strlen((string) $itemXml->title)
                ? (string) $itemXml->title : "unknown title"
        );

    }

    /**
     * Protected support method for getMyTransactions.
     *
     * @param array $itemXml simplexml object of item data
     * @param array $patron array
     *
     * @throws DateException - TODO
     * @return array Keyed data for display by template files
     */
    protected function processMyTransactionsData($itemXml, $patron = false)
    {

        $dueDate = substr((string) $itemXml->dueDate, 0, 10);
        $dueTime = substr((string) $itemXml->dueDate, 11);
        
        $dueStatus = ((string) $itemXml->overDue == 'true') ? "overdue" : "";
        
        $transactions = array(
            'id' => substr((string) $itemXml->catalogueId, strpos((string) $itemXml->catalogueId, '-')+1),
            'item_id' => (string) $itemXml->itemId,
            'duedate' => $dueDate,
            'dueTime' => $dueTime,
            'dueStatus' => $dueStatus,
            'volume' => '',
            'publication_year' => '',
            'title' => strlen((string) $itemXml->title)
                ? (string) $itemXml->title : "unknown title"
        );
		$renewData = $this->checkRenewalsUpFront
            ? $this->isRenewable($patron['id'], $transactions['item_id'])
            : array('message' => 'renewable', 'renewable' => true);

        $transactions['renewable'] = $renewData['renewable'];
        $transactions['message'] = $renewData['message'];
		
		return $transactions;
		
    }
    

	/* TODO: document this */
    public function getRecord($id)
    {

        /*
        http://localhost:8080/oledocstore/document?docAction=instanceDetails&format=xml&bibIds=1458569
        */
		$uri = $this->docService . '?docAction=instanceDetails&format=xml&bibIds=' . $id;

		/* TODO: use the zend http service and throw appropriate exception */
		$xml = simplexml_load_string(file_get_contents($uri));

		
		$xml->registerXPathNamespace('ole', 'http://ole.kuali.org/standards/ole-instance');
		$xml->registerXPathNamespace('circ', 'http://ole.kuali.org/standards/ole-instance-circulation');

        return $xml;
    }

    /**
     * Get Status
     *
     * This is responsible for retrieving the status information of a certain
     * record.
     *
     * @param string $id The record id to retrieve the holdings for
     *
     * @throws ILSException
     * @return mixed     On success, an associative array with the following keys:
     * id, availability (boolean), status, location, reserve, callnumber.
     */
    public function getStatus($id)
    {
        
        $record = $this->getRecord($id);

        $holdingsXML = $record->xpath('//ole:oleHoldings');
        $callNumber = $holdingsXML[0]->children('ole', true)->callNumber->children('ole', true)->number;
		$location = '';
		$locationsXML = $record->xpath('//circ:locationLevel');
		foreach($locationsXML as $locationXML) {
			$location .= (string)$locationXML->children('ole', true)->name . "/";
		}
        $itemsXML = $record->xpath('//ole:item');
        $items = array();

        foreach($itemsXML as $itemXML) {

			$status = $itemXML->children('circ', true)->itemStatus->children()->fullValue;
			$available = ($status != 'LOANED') ? true:false;

			$item['status'] = $status;
			$item['location'] = $location;
			$item['reserve'] = '';
			$item['availability'] = $available;
			$item['callnumber'] = $callNumber;
			$item['id'] = $id;

            $items[] = $item;
        }

        return $items;
    }
	
	/**
	 * TODO: document this
	 *
	 */
	public function getItemStatus($itemXML) {

		$status = $itemXML->children('circ', true)->itemStatus->children()->fullValue;
		$available = ($status != 'LOANED') ? true:false;

		$item['status'] = $status;
		$item['location'] = '';
		$item['reserve'] = '';
		$item['availability'] = $available;

		return $item;
	}
	
    /**
     * Get Statuses
     *
     * This is responsible for retrieving the status information for a
     * collection of records.
     *
     * @param array $idList The array of record ids to retrieve the status for
     *
     * @throws ILSException
     * @return array        An array of getStatus() return values on success.
     */
    public function getStatuses($idList)
    {
        $status = array();
        foreach ($idList as $id) {
            $status[] = $this->getStatus($id);
        }
        return $status;
    }


    /**
     * Get Holding
     *
     * This is responsible for retrieving the holding information of a certain
     * record.
     *
     * @param string $id     The record id to retrieve the holdings for
     * @param array  $patron Patron data
     *
     * @throws \VuFind\Exception\Date
     * @throws ILSException
     * @return array         On success, an associative array with the following
     * keys: id, availability (boolean), status, location, reserve, callnumber,
     * duedate, number, barcode.
     */
    public function getHolding($id, $patron = false)
    {

        $record = $this->getRecord($id);
        $holdingsXML = $record->xpath('//ole:oleHoldings');
        $callNumber = (string) $holdingsXML[0]->children('ole', true)->callNumber->children('ole', true)->number;
		$location = '';
		$locationsXML = $record->xpath('//circ:locationLevel');
		foreach($locationsXML as $locationXML) {
			$location .= (string)$locationXML->children('ole', true)->name . "/";
		}
        $itemsXML = $record->xpath('//ole:item');
		
        $items = array();
        foreach($itemsXML as $itemXML) {
            //var_dump($itemXML);
            $status = (string) $itemXML->children('circ', true)->itemStatus->children()->fullValue;
            $available = ($status != 'LOANED') ? true:false;
            $item['id'] = $id;
            $item['availability'] = $available;
            $item['status'] = $status;
            $item['location'] = $location;
            $item['reserve'] = '';
            $item['callnumber'] = $callNumber;
            $item['duedate'] = (string) $itemXML->dueDateTime;
            $item['returnDate'] = '';
            $item['number'] = '';
            $item['requests_placed'] = '';
            $item['barcode'] = (string) $itemXML->children('ole', true)->accessInformation->children('ole', true)->barcode;
            $item['notes'] = '';
            $item['summary'] = '';
			$item['item_id'] = (string) $itemXML->children('ole', true)->itemIdentifier;
			
			// Holds
			$is_holdable = false;
			$holdtype = 'hold';
			$addLink = false;
			$holdOverride = '';
			
			if ($patron && $available) {
				$is_holdable = true;
				$holdtype = 'hold';
				$addLink = true;
				$holdOverride = '';
			}
			
			$item['is_holdable'] = $is_holdable;
			$item['holdtype'] = $holdtype;
			$item['addLink'] = $addLink;
            $item['holdOverride'] = '';

            //var_dump($item);

            $items[] = $item;
        }

        return $items;

    }
    /**
     * Place Hold
     *
     * Attempts to place a hold or recall on a particular item and returns
     * an array with result details or throws an exception on failure of support
     * classes
     *
     * @param array $holdDetails An array of item and patron data
     *
     * @throws ILSException - TODO
     * @return mixed An array of data on the request including
     * whether or not it was successful and a system message (if available)
     */
    public function placeHold($holdDetails)
    {
		/*		http://localhost:8080/olefs/circulation?service=placeRequest&patronId=20235562K&operatorId=API&itemBarcode=005123641675530699&requestType=Page%2fHold+Request
				http://localhost:8080/olefs/circulation?service=placeRequest&patronId=10100055U&operatorId=API&itemBarcode=33165972443029224&requestType=Page%2fHold+Request
		*/
		
		
		$patron = $holdDetails['patron'];
		$patronId = $patron['id'];
		$operatorId = 'API';
		$service = 'placeRequest';
		$requestType = 'Page%2fHold+Request';
		$bibId = $holdDetails['id'];
		$itemBarcode = $holdDetails['barcode'];
		
		$record = $this->getRecord($bibId);
		
		$uri = $this->circService . "?service={$service}&patronId={$patronId}&operatorId={$operatorId}&itemBarcode={$itemBarcode}&requestType={$requestType}";
		
		//var_dump($uri);
		
		$request = new Request();
		$request->setMethod(Request::METHOD_POST);
		$request->setUri($uri);

		$client = new Client();
		$client->setOptions(array('timeout' => 30));
		
		$response = $client->dispatch($request);
		
		$content_str = $response->getContent();
		/* TODO: Hack to strip extraneous characters from response */
		$content = substr($content_str, strpos($content_str, '<'), (strrpos($content_str, '>') - strpos($content_str, '<') + 1)); 

		/* TODO: this will always be 201 */
		$statusCode = $response->getStatusCode();

		$xml = simplexml_load_string($content);
		$msg = $xml->xpath('//message');
		
		/* TODO: this is a hack to get the appropriate boolean response from the service. The circ API always returns 'OK' regardless is the operation was allowed */
		$success = (stristr((string)$msg[0], 'succes')) ? true:false;

		return $this->returnString($success, (string)$msg[0]);

	}

	/**
     * Hold Error
     *
     * Returns a Hold Error Message
     *
     * @param string $msg An error message string
     *
     * @return array An array with a success (boolean) and sysMessage key
     */
    protected function returnString($success,$msg)
    {
        return array(
                    "success" => $success,
                    "sysMessage" => $msg
        );
    }
	
	/* TODO: config this using options from OLE */
	public function getPickUpLocations($patron = false, $holdDetails = null)
    {

		$pickResponse[] = array(
			"locationID" => '1',
			"locationDisplay" => 'Location 1'
		);

        return $pickResponse;
    }
	
	/* TODO: document this */
	public function getDefaultPickUpLocation($patron = false, $holdDetails = null)
    {
        return $this->defaultPickUpLocation;
    }
	
	/**
     * Determine Renewability
     *
     * This is responsible for determining if an item is renewable
     *
     * @param string $patronId The user's patron ID
     * @param string $itemId   The Item Id of item
     *
     * @return mixed Array of the renewability status and associated
     * message
     */
	 /* TODO: implement this with OLE data */
    protected function isRenewable($patronId, $itemId)
    {
		$renewData['message'] = "renable";
        $renewData['renewable'] = true;

        return $renewData;
    }

	/**
     * Support method for VuFind Hold Logic. Take an array of status strings
     * and determines whether or not an item is holdable based on the
     * valid_hold_statuses settings in configuration file
     *
     * @param array $statusArray The status codes to analyze.
     *
     * @return bool Whether an item is holdable
     */
	 /* TODO: implement this with OLE data */
    protected function isHoldable($item)
    {
        // User defined hold behaviour
        $is_holdable = true;
		
        return $is_holdable;
    }
	
    /**
     * Get Renew Details
     *
     *
     * @param array $checkOutDetails An array of item data
     *
     * @return string Data for use in a form field
     */
    public function getRenewDetails($checkOutDetails)
    {
      //var_dump($checkOutDetails);
      $renewDetails = $checkOutDetails['item_id'] . ',' . $checkOutDetails['id'];
	//$renewDetails['item_id'] = $checkOutDetails['id'];
        return $renewDetails;
    }
	
	/**
     * Renew My Items
     *
     * Function for attempting to renew a patron's items.  The data in
     * $renewDetails['details'] is determined by getRenewDetails().
     *
     * @param array $renewDetails An array of data required for renewing items
     * including the Patron ID and an array of renewal IDS
     *
	 * @throws ILSException - TODO
     * @return array              An array of renewal information keyed by item ID
     */
	 /* TODO: implement error messages from OLE once status codes are returned correctly
	 HTTP/1.1 200 OK
	 <renewItem>
	  <message>Patron has more than $75 in Replacement Fee Charges. (OR) Patron has more than $150 in overall charges. (OR) The item has been renewed the maximum (1) number of times. (OR) </message>
	</renewItem>
	
	 */
    public function renewMyItems($renewDetails)
	{
		/*
		http://localhost:8080/olefs/circulation?service=renewItem&patronId=10100055U&operatorId=API&itemBarcode=9860950159307095
		
		http://localhost:8080/olefs/circulation?service=renewItem&patronId=10100055U&operatorId=API&itemBarcode=005123641675530699
		
		*/
		//var_dump($renewDetails);
		
		$patron = $renewDetails['patron'];
		$patronId = $patron['id'];
		$operatorId = 'API';
		$service = 'renewItem';
		
		$finalResult = array();
		
		foreach ($renewDetails['details'] as $key=>$details) {
		  $details_arr = explode(',', $details);
		  $itemBarcode = $details_arr[0];
		  $item_id = $details_arr[1];

			//$record = $this->getRecord($bibId);
			
			$uri = $this->circService . "?service={$service}&patronId={$patronId}&operatorId={$operatorId}&itemBarcode={$itemBarcode}";
			
			//var_dump($uri);
			
			$request = new Request();
			$request->setMethod(Request::METHOD_POST);
			$request->setUri($uri);

			$client = new Client();

			$response = $client->dispatch($request);
			
			//var_dump($response);
			$finalResult['details'][$itemBarcode] = array(
								"success" => false,
								"new_date" => false,
								"item_id" => $itemBarcode,
								"sysMessage" => $response->getContent()
								);
			
		}
		//var_dump($finalResult);
		return $finalResult;
	}
	
    /**
     * Get Purchase History
     *
     * This is responsible for retrieving the acquisitions history data for the
     * specific record (usually recently received issues of a serial).
     *
     * @param string $id The record id to retrieve the info for
     *
     * @throws ILSException
     * @return array     An array with the acquisitions data on success.
     */
    public function getPurchaseHistory($id)
    {
        // TODO
        return array();
    }
}
