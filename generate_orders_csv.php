#!/usr/bin/php -dmemory_limit=2048M
<?php
set_time_limit(100);
ini_set('memory_limit', '2048M');


/* @load */
require_once('loader.php'); 

/* @instantiate */
$a = new XMLFeeds();

/**
 * XML Feeds for Orders
 *
 * @author Josh Schuler
 */
class XMLFeeds {
    function __construct() {
        $m = $this->getMode();

        // use cached version
        if ($m == 'cache') {
          if (   (!isset( $_SERVER['PHP_AUTH_USER'] ))
              || (!isset($_SERVER['PHP_AUTH_PW']))
              || ( $_SERVER['PHP_AUTH_USER'] != 'admin' )
              || ( $_SERVER['PHP_AUTH_PW'] != 'feeds51!' ) )
          {
              header( 'WWW-Authenticate: Basic realm="Secured Area"' );
              header( 'HTTP/1.0 401 Unauthorized' );
              echo 'Authorization Required.';
              exit;
          } 
          header("Content-type: text/csv"); 
          header("Cache-Control: no-store, no-cache");

          $csv = file_get_contents('./feed_current.csv');
          echo $csv;

        // generate
        } else 
  	$this->getOrderData();
		header("Content-type: text/csv"); 
        header("Cache-Control: no-store, no-cache");
		$csv = file_get_contents('./feed_current.csv');
        echo $csv;
    }
    private function getMode() {
        // Define some CLI options
        $getopt = new Zend_Console_Getopt(array(
            'generate|g' => 'Load database with sample data',
        ));
        try {
            $getopt->parse();
        } 
        catch (Zend_Console_Getopt_Exception $e) {
            // Bad options passed: report usage
            echo $e->getUsageMessage();
            return false;
        }
        
        // Initialize values based on presence or absence of CLI options
        $generate = $getopt->getOption('g');
        if ($generate) return 'generate';
        else return 'cache';
    }
    public function createCSV($orders) {
        if (!count($orders)) return;

        // loop through all entities and preg_match the skus to find parents
        $parent_skus = $this->getParentSkus();
		
        $csv = 'order_id,page_id,first_name,last_name,email,order_date'."\n";
        foreach ($orders as $o) {
			if($o['items']){
				foreach ($o['items'] as $v) {
				  $my_parent_sku = false;
				  
				  
				 foreach ($parent_skus as $p) {
					if (strlen($p['sku']) > 3 && preg_match('/'.str_replace('-','\-',$p['sku']).'/', $v['sku'])) $my_parent_sku = $p['sku'];
				  }
				  if (!empty($my_parent_sku)) {
					$v['parent_sku'] = $my_parent_sku;
				  }
	  
				  if (empty($v['parent_sku'])) continue; 

				  $rows[] = $o['order_id'].','.
							$v['parent_sku'].','.
							$o['first_name'].','.
							$o['last_name'].','.
							$o['email'].','.
							strftime("%m-%d-%Y",strtotime($o['created_at']));

					// add the foundation sku if it detects any of these:
					if (preg_match('/TR-FND|TXL-FND|FR-FND|Q-FND|EK-FND|CK-FND|SQ-FND|SEK-FND|SCK-FND/', $v['sku'])) {
							$rows[] = $o['order_id'].','.
									  'AC-FNDTN,'.
									  $o['first_name'].','.
									  $o['last_name'].','.
									  $o['email'].','.
									  strftime("%m-%d-%Y",strtotime($o['created_at']));
					}
					
				}
            }
        }
        $csv .= implode("\n", $rows);
        file_put_contents('./feed_current.csv', $csv);
    }
    public function findParentSku($sku, $attribute_id) {
        $r = Doctrine_Core::getTable('CatalogProductEntity')
          ->findOneBySku($sku);
        if ($r instanceOf Doctrine_Record) {
          $q = Doctrine_Core::getTable('CatalogProductEntityVarchar')
            ->findOneByEntityIdAndAttributeId(
              $r->entity_id, $attribute_id
          );
          if ($q instanceOf Doctrine_Record) {
            $parent_sku = $q->value;
          } 
        }
        return $parent_sku;
    }
    public function findAttributeId($code = 'sku') {
        $s = Doctrine_Core::getTable('EavAttribute')
          ->findOneByAttributeCode($code);

        if ($s instanceOf Doctrine_Record) {
            $attribute_id = $s->attribute_id;
            $s->free();
        }

        return $attribute_id;
    }
    public function getParentSkus() {
       $q = Doctrine_Core::getTable('CatalogProductEntity')
          ->createQuery('c')
          ->select('c.sku')
          ->orderBy('c.entity_id asc');

       $res = $q->execute();
       $thi = $res->toArray();
       $res->free();
       return $thi;
    }
    public function getOrderData() {
      $month_ago = date("Y-m-d", strtotime("-1 month"));
     // echo "\n Executing From: $month_ago \n";
       $record = Doctrine_Core::getTable('SalesFlatOrder')
          ->createQuery('u')
          ->select('u.entity_id')
          ->where('created_at > "'.$month_ago.'"')
          ->orderBy('u.entity_id asc');

       $res = $record->execute();

       foreach ($res as $v) {    

         if (!empty($v->CustomerEntity->email)) {
           $orders[$v->entity_id]['email'] = $v->CustomerEntity->email;
           foreach ($v->CustomerEntity->CustomerEntityVarchar as $c) { 
              $data = $c->toArray();
              if ($data['attribute_id'] == '5') $first_name = $data['value'];                          
              if ($data['attribute_id'] == '7') $last_name = $data['value'];                          
           }
           
           $orders[$v->entity_id] = array(
             'entity_id'=> $v->entity_id,
             'order_id'=> $v->increment_id,
             'first_name'=> $first_name,
             'last_name'=> $last_name,
             'email'=> $orders[$v->entity_id]['email'],
             'created_at'=> $v->created_at,
             'updated_at'=> $v->updated_at
           );


            $r = Doctrine_Core::getTable('SalesFlatOrderItem')
              ->findByOrderId($v->entity_id);

            foreach ($r as $o) { $_parent_sku = '';

              $_parent_sku = $this->findParentSku($o->sku, $this->findAttributeId());

              $orders[$v->entity_id]['items'][] = array(
                'name'=> $o->name,
                'sku'=> $o->sku,
                'parent_sku' => $_parent_sku
              );
            }
            $r->free();

         } else {

            //---------------------------------------------
            // sales_order_entity
            //---------------------------------------------

            $y = Doctrine_Core::getTable('SalesFlatOrderAddress')
              ->findByParentId($v->entity_id);
        
            foreach ($y as $yv) {
                $orders[$v->entity_id]['first_name'] = $yv->firstname;
                $orders[$v->entity_id]['last_name'] = $yv->lastname;

            }
            $y->free();
            //---------------------------------------------
            // sales_flat_order/quote _item
            //---------------------------------------------

            $f = Doctrine_Core::getTable('SalesFlatOrderItem')
              ->findByOrderId($v->entity_id);
            
            foreach ($f as $fv) {
              $x = Doctrine_Core::getTable('SalesFlatQuoteItem')
                ->findByItemId($fv->quote_item_id);
              foreach ($x as $xv) { $_parent_sku = '';
                $_parent_sku = $this->findParentSku($xv->sku, $this->findAttributeId());

                // loop through all entities and preg_match the skus to find parents
                $_parent_skus = $this->getParentSkus();

                $orders[$v->entity_id]['quote_id'] = $xv->quote_id;
                $orders[$v->entity_id]['items'][] = array(
                  'name'=> $xv->name,
                  'sku'=> $xv->sku,
                  'parent_sku' => $_parent_sku
                );
              }

            }
            $f->free();

            //---------------------------------------------
            // sales_flat_quote / _address
            //---------------------------------------------

            if (!empty($orders[$v->entity_id]['quote_id'])) {
              $w = Doctrine_Core::getTable('SalesFlatQuoteAddress')
                ->findOneByQuoteId(
                    $orders[$v->entity_id]['quote_id'] 
                );
              if ($w instanceOf Doctrine_Record) {
                $orders[$v->entity_id]['first_name'] = $w->firstname;
                $orders[$v->entity_id]['last_name']  = $w->lastname;
                $orders[$v->entity_id]['email']      = $w->email;
                $w->free();
              }

              $t = Doctrine_Core::getTable('SalesFlatQuote')
                ->findOneByEntityId(
                    $orders[$v->entity_id]['quote_id'] 
                );
              if ($t instanceOf Doctrine_Record) {
                $orders[$v->entity_id]['order_id'] = $t->reserved_order_id;
                $orders[$v->entity_id]['created_at'] = $t->created_at;
                $orders[$v->entity_id]['updated_at'] = $t->updated_at;
                $t->free();
              }

            }
         }

       }

       //print_r ($orders);
       $this->createCSV($orders);
    }
}
