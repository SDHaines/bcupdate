#!/usr/local/bin/php
<?php
require_once '/usr/local/valCommon/BigComm.php';
#require_once '/usr/local/valCommon/SqlServer.php';
require_once '/usr/local/valCommon/Counterpoint.php';
#$conn = vcSqlServerConnect( $vcSqlServerWriterConnectionOptions );
$ignoredItemList = "'10001', '10024', '10025','AGE_VERIFY','CLEAR_DL_DATA',
'CUST_ADD','LOTTO','LOTTO P/O','MISC','MO FEE','MONEYXFER',
'SCRATCHER P/O','SWIPE_DL','TEMPLATE'";
$oldItems = array ();
$newItems = array ();
$updateItems = array ();
$oldBcInfo = array();

$tsql = "SELECT
BC_ITEM_NO AS 'id',
ADDL_DESCR_1 AS 'name',
ITEM_NO AS 'sku',
PRC_1 AS 'price',
CASE TAX_CATEG_COD
  WHEN 'ALCOHOL' THEN '0'
  ELSE '5'
END 'tax_class_id',
QTY_AVAIL AS 'inventory_level',
BARCOD AS 'upc'
FROM VI_IM_ITEM_WITH_INV_AND_INV_TOTS 
WHERE BARCOD IS NOT NULL
AND ITEM_TYP = 'I' 
AND STAT = 'A'
AND ITEM_NO NOT IN ( $ignoredItemList )
";

#$stmt = vcSqlServerQuery( $conn, $tsql, "vcSqlServerQuery failed execution can not continue\n" );
$data = counterpointQuickQuery( $tsql );

if ( $data === null or $data === false ){ die ( "Can't query database for item list\n" ); }
foreach( $data as $row ) {

# while ( $row = sqlsrv_fetch_array( $stmt, SQLSRV_FETCH_ASSOC ) ) {
  #printf ( "row: %04d sku: %05d name: %-80s id: %04d\n", $rowCount, $row['sku'],$row['name'],$row['id']);

  $qtyAvail = (int) $row['inventory_level'];
  if ( $qtyAvail < 0 ) { $qtyAvail = 0; }
  $row['price'] = sprintf (" %.02f", $row['price'] );

  if ( isset( $row['id'] ) ) {
    #print "adding " . $row['name'] . " to oldItems\n";
    $oldItems[$row['sku']] = array ( 'id' => $row['id'], 'name' => $row['name'], 'inventory_level' => $qtyAvail, 'description' => $row['name'],
                                     'price' =>  $row['price'], 'cost_price' => $row['price'] );
  } else {
     #print "adding " . $row['name'] . " to newItems\n";
     $newItems[$row['sku']] = ( object ) array ( 'name' => $row['name'], 'type' => 'physical', 'sku' => $row['sku'], 
                        'description' => $row['name'], 'weight' => '1', 'price' =>  $row['price'],
                        'cost_price' => $row['price'], 'tax_class_id' => $row['tax_class_id'], 
                        'inventory_level' => $qtyAvail , 'inventory_tracking' => 'product',
                        'is_visible' => false, 'upc' => $row['upc'], 'availability' => 'available', 'categories' => [23] );
  }

}
$nextPage = 1;
while ( $nextPage ){
    $response = vcCurl( "GET", $vcBigCommProductUrl, $vcBigCommCurlHeaders, $vcBigCommCurlData );
    if ( $response->responseCode == 200 ){
       $nextPage = vcBigCommGetNextPage( $response );

       for ( $i = 0; $i < $response->body->meta->pagination->count; $i++ ) {
          $p = $response->body->data[$i];
          
          if ( ! isset ( $oldItems[$p->sku] ) )
             continue;
          $currInventory = $oldItems[$p->sku]['inventory_level'];
          $currPrice = $oldItems[$p->sku]['price'];

          if ($p->inventory_level != $currInventory or $p->price != $currPrice ) {
            $updateItems[] = $oldItems[$p->sku];
            $oldBcInfo[$p->sku] = $p;
            #print "UPD: OLIL: $p->inventory_level DBIL: $currInventory OLP: $p->price DBP: $currPrice\n";
          }
          #else {
          #  print "nUPD: nOLIL: $p->inventory_level nDBIL: $currInventory nOLP: $p->price nDBP: $currPrice\n";
          #}
       }
    } else {
        print_r( $response );
    }
}
$itemCount = count( $updateItems );
print "################### update ###################\n";
printf ( "%3d item%s need%s to be updated\n", $itemCount, $itemCount == 1 ? "" : "s", $itemCount == 1 ? "s" : "" );
while ( $update10 = array_splice ( $updateItems, 0, 10 ) ) {
  #foreach ( $update10 as $item ) { #force single item update for debugging
    #$data = json_encode( $item );
    $data = json_encode( $update10 ); #force single item update for debugging
    $response = vcCurl( "PUT", $vcBigCommProductUrl, $vcBigCommCurlHeaders, $data );
    #print "STATUS: $response->responseCode\n";
    if ( $response->responseCode == 200 ) {
      
      #print_r ( $response );
      
      $d = $response->body->data;
      $count = count( $d );
      for ( $i = 0; $i < $count; $i++ ) {
        $sku = $d[$i]->sku;
        $oldQty = $oldBcInfo[$sku]->inventory_level;
        $oldPrice = $oldBcInfo[$sku]->price;
        $newQty = $d[$i]->inventory_level;
        $newPrice = $d[$i]->price;
        print "item_no: {$sku} {$d[$i]->name} updated sucessfully. ";
        printf ( "%s%s\n", 
        $oldQty != $newQty ? "qty: $oldQty->$newQty " : "", 
        $oldPrice != $newPrice ? "price: $oldPrice->$newPrice" : "" );
      }
      
    } else {
      print "******************* Items not updated *******************\n";
      print_r( $response );
      print_r( $update10 );
      print "******************* Items not updated *******************\n\n";
      #exit;
    }
  #} #force single item update for debugging
}
print "################### update ###################\n\n";

$itemCount = count ( $newItems );
print "#*#*#*#*#*#*#*#*#*# add new #*#*#*#*#*#*#*#*#*#\n";
printf ( "%3d item%s need%s to be added\n", 
$itemCount, $itemCount == 1 ? "" : "s", 
$itemCount == 1 ? "s" : "" );

foreach ( $newItems as $item ) {
    $data = json_encode( $item );
    $response = vcCurl( "POST", $vcBigCommProductUrl, $vcBigCommCurlHeaders, $data );
    if ( $response->responseCode == 200 ){
      $d = $response->body->data;
      print "item_no: $d->sku $d->name added as id: $d->id qty: $d->inventory_level price: $d->price\n";
      $bcItemNo = $response->body->data->id;
      $sku = $response->body->data->sku;
      $tsql = "UPDATE IM_ITEM SET BC_ITEM_NO = '$bcItemNo' WHERE ITEM_NO = '$sku'";
      #$stmt = vcSqlServerQuery( $conn, $tsql, "vcSqlServerQuery failed execution can not continue\n" );
      $dummy = null;
      $result = counterpointQuickQuery( $tsql, $dummy, $dummy, true, true );
      print "cell BC_ITEM_NO for IM_ITEM.ITEM_NO $sku has been updated with value $bcItemNo\n";
    } else {
      print "******************* Big Commerce not updated *******************\n";
      print_r( $response );
      print_r( $item );
      print "******************* Big Commerce not updated *******************\n";
      #vcSqlServerCleanUp( $conn, $stmt );
    }
  }
  print "#*#*#*#*#*#*#*#*#*# add new #*#*#*#*#*#*#*#*#*#\n\n";
  #vcSqlServerCleanUp( $conn, $stmt );
  
  exit;

?>