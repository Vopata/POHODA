<?php
/**
 * Plugin Name: POHODA-MO
 * Description: Plugin pro export objednávek do formátu POHODA
 * Version: 1.1
 * Author: Martin Opatrný
 */

// Přidání stránky nastavení do administrace WordPressu
function pohoda_mo_add_admin_page() {
    add_menu_page(
        'POHODA-MO',
        'POHODA-MO',
        'manage_options',
        'pohoda-mo',
        'pohoda_mo_admin_page_callback',
        'dashicons-admin-generic',
        110
    );
}
add_action('admin_menu', 'pohoda_mo_add_admin_page');

// Registrace nastavení
function pohoda_mo_settings_init() {
    // Registrace nové sekce nastavení
    add_settings_section(
        'pohoda_mo_section',
        'Nastavení exportu objednávek',
        '',
        'pohoda_mo'
    );

    // Registrace pole pro zadání časového rozmezí
    add_settings_field(
        'pohoda_mo_date_range',
        'Časové rozmezí',
        'pohoda_mo_date_range_callback',
        'pohoda_mo',
        'pohoda_mo_section'
    );
    register_setting('pohoda_mo', 'pohoda_mo_date_range');

    // Registrace pole pro výběr stavů objednávek
    add_settings_field(
        'pohoda_mo_order_statuses',
        'Stavy objednávek',
        'pohoda_mo_order_statuses_callback',
        'pohoda_mo',
        'pohoda_mo_section'
    );
    register_setting('pohoda_mo', 'pohoda_mo_order_statuses');
    
    // Registrace pole pro zadání informací o identitě
    add_settings_field(
        'pohoda_mo_identity',
        'Informace o identitě',
        'pohoda_mo_identity_callback',
        'pohoda_mo',
        'pohoda_mo_section'
    );
    register_setting('pohoda_mo', 'pohoda_mo_identity');
}
add_action('admin_init', 'pohoda_mo_settings_init');

// Funkce pro zpracování požadavku na generování XML souboru
function pohoda_mo_process_request_xml() {
    if (isset($_POST['pohoda_mo_generate_xml'])) {
        // testovací data
        $date_range = array('start' => '2023-01-01', 'end' => '2023-12-31');
        pohoda_mo_export_xml($date_range);
    }
}
add_action('admin_init', 'pohoda_mo_process_request_xml');

function pohoda_mo_woocommerce_api_function($date_range) {
    // URL WooCommerce API
    $base_url = 'https://antikvariatucebnicezkouska.webosvet.cz/wp-json/wc/v3/orders?consumer_key=ck_cdd93a8c030dddc785e34301404355640df45abb&consumer_secret=cs_607dfe243bd93661c927cf1a4b01f55e372cfa9d&status=completed&per_page=10';

    // Add date filter parameters if date range is set
    if (!empty($date_range['start'])) {
        $base_url .= '&after=' . urlencode($date_range['start'] . 'T00:00:00');
    }
    if (!empty($date_range['end'])) {
        $base_url .= '&before=' . urlencode($date_range['end'] . 'T23:59:59');
    }

    // WooCommerce API consumer key
    $consumer_key = 'ck_cdd93a8c030dddc785e34301404355640df45abb';

    // WooCommerce API consumer secret
    $consumer_secret = 'cs_607dfe243bd93661c927cf1a4b01f55e372cfa9d';

    // WordPress HTTP API arguments
    $args = array(
        'headers' => array(
            'Authorization' => 'Basic ' . base64_encode( $consumer_key . ':' . $consumer_secret )
        ),
        'timeout' => 300 // Zvýšení časového limitu na 300 sekund
    );

    $page = 1;
    $orders = array();

    do {
        // Send GET request
        $response = wp_remote_get( $base_url . '&page=' . $page, $args );

        // Check if the request was successful
        if (is_wp_error($response)) {
            error_log('POHODA-MO Error: Failed to send request: ' . $response->get_error_message());
            break;
        }

        // Decode the response body
        $new_orders = json_decode( wp_remote_retrieve_body( $response ) );

        // Check if json_decode() returned an error
        if (json_last_error() !== JSON_ERROR_NONE) {
            error_log('POHODA-MO Error: Failed to decode JSON: ' . json_last_error_msg());
            break;
        }

        // Log the response data
        error_log(print_r($new_orders, true));

        // If no orders were returned, we're done
        if (empty($new_orders)) {
            break;
        }

        // Add the new orders to the list
        $orders = array_merge($orders, $new_orders);

        // Go to the next page
        $page++;
    } while (count($orders) < 100); // We limit the amount of orders fetched to 100

    // Save the orders to the database
    update_option( 'pohoda_mo_orders', $orders );
}

// We only fetch orders if the user requested it
function pohoda_mo_process_request_data() {
    if (isset($_POST['pohoda_mo_generate_data']) && isset($_POST['pohoda_mo_date_range'])) {
        $date_range = $_POST['pohoda_mo_date_range'];
        pohoda_mo_woocommerce_api_function($date_range);
    }
}
add_action('admin_init', 'pohoda_mo_process_request_data');


function pohoda_mo_export_xml($date) {
    // Vytvoření nového XML dokumentu
    $xml = new SimpleXMLElement('<?xml version="1.0" encoding="Windows-1250"?><dat:dataPack version="2.0" id="Usr01" ico="13107011" key="4b17b890-e9c6-4687-b80d-44f15ced8754" programVersion="13403.6 (11.7.2023)" application="Transformace" note="U�ivatelsk� export" xmlns:dat="http://www.stormware.cz/schema/version_2/data.xsd"></dat:dataPack>');

    $batch_size = 100;
    $page = 1;

    $orders = get_option('pohoda_mo_orders');

    do {
        $dataPackItem = $xml->addChild('dat:dataPackItem', null, 'http://www.stormware.cz/schema/version_2/data.xsd');
        $dataPackItem->addAttribute('id', 'Usr01');
        $dataPackItem->addAttribute('version', '2.0');

        // Procházení objednávek a přidávání do XML
        foreach ($orders as $order) {
            $dataPackItem->addChild('ord:order', null, 'http://www.stormware.cz/schema/version_2/order.xsd');
         
            // Získání dat objednávky
            $order_data = $order->get_data(); 

            // Vytvoření XML elementu pro objednávku
            $order_xml = $xml->addChild('dat:dataPackItem');
            $order_xml->addAttribute('version', '2.0');
            $order_xml->addAttribute('id', 'Usr01 (001)');

    $invoice = $order_xml->addChild('inv:invoice');
    $invoice->addAttribute('version', '2.0');
    $invoice->addAttribute('xmlns:inv', 'http://www.stormware.cz/schema/version_2/invoice.xsd');

    $invoice_header = $invoice->addChild('inv:invoiceHeader');
    // Zde byste přidali další elementy a atributy podle potřeby

    $invoice_header->addChild('inv:invoiceType', 'issuedCorrectiveTax');
    $number = $invoice_header->addChild('inv:number');
    if ($order instanceof WC_Order_Refund) {
    $order_number = $order->get_parent_id();
} else {
    $order_number = $order->get_order_number();
}

$number->addChild('typ:numberRequested', $order_number);
$invoice_header->addChild('inv:symVar', $order_number);
    $date_created = $order->get_date_created();
    $date_paid = $order->get_date_paid();

    $invoice_header->addChild('inv:date', $date_created->date('Y-m-d'));
    $invoice_header->addChild('inv:accountingPeriod', $date_created->date('m'));

    if ($date_paid !== null) {
        $invoice_header->addChild('inv:dateDue', $date_paid->date('Y-m-d'));
    } else {
        $invoice_header->addChild('inv:dateDue', $date_created->date('Y-m-d'));
    }

    $invoice_header->addChild('inv:symConst', '123456');
    $invoice_header->addChild('inv:symSpec', 'SPEC');
    $invoice_header->addChild('inv:paymentType', 'paymentAdvance');
    $invoice_header->addChild('inv:note', 'Note for the order');

    // Přidání informací o zákazníkovi
    $partner_identity = $invoice_header->addChild('inv:partnerIdentity');
    $address = $partner_identity->addChild('typ:address');

     // Pokud je objednávka náhrada, získáme údaje o společnosti z původní objednávky
     if ($order instanceof WC_Order_Refund) {
                    $parent_order = wc_get_order($order->get_parent_id());
                    $address->addChild('typ:company', $parent_order->get_billing_company());
                } else {
                    $address->addChild('typ:company', $order->get_billing_company());
                }


  //  $address->addChild('typ:company', $order->get_billing_company());
    $address->addChild('typ:division', '');
    $address->addChild('typ:name', $order->get_billing_first_name() . ' ' . $order->get_billing_last_name());
    $address->addChild('typ:city', $order->get_billing_city());
    $address->addChild('typ:street', $order->get_billing_address_1());
    $address->addChild('typ:zip', $order->get_billing_postcode());
    $address->addChild('typ:ico', '12345678');
    $address->addChild('typ:dic', 'CZ12345678');
    $address->addChild('typ:phone', $order->get_billing_phone());
    $address->addChild('typ:email', $order->get_billing_email());

    // Přidání elementu inv:invoiceSummary
    $invoice_summary = $invoice->addChild('inv:invoiceSummary');
    $invoice_summary->addChild('inv:roundingDocument', 'none');
    $invoice_summary->addChild('inv:roundingVAT', 'none');
    $invoice_summary->addChild('inv:typeCalculateVATInclusivePrice', 'VATOriginalMethod');
    $home_currency = $invoice_summary->addChild('inv:homeCurrency');
    $home_currency->addChild('typ:priceNone', $order->get_total());
    // ... a tak dále pro další elementy

    // Přidání položek objednávky
    $items = $order->get_items();
    foreach ($items as $item) {
        $invoice_item = $invoice->addChild('inv:invoiceItem');
        $invoice_item->addChild('inv:text', $item->get_name());
        $invoice_item->addChild('inv:quantity', $item->get_quantity());
        $invoice_item->addChild('inv:unit', 'ks');
        $invoice_item->addChild('inv:coefficient', '1');
        $invoice_item->addChild('inv:payVAT', 'false');
        $invoice_item->addChild('inv:rateVAT', 'high');
        $invoice_item->addChild('inv:discountPercentage', '0');
        $home_currency = $invoice_item->addChild('inv:homeCurrency');
        $home_currency->addChild('typ:unitPrice', $order->get_item_total($item, false));
        $home_currency->addChild('typ:price', $order->get_line_total($item, false));
        $home_currency->addChild('typ:priceVAT', $order->get_line_tax($item));
        $home_currency->addChild('typ:priceSum', $order->get_line_total($item, true));
    }

     // Získání uložených hodnot
    $identity = get_option('pohoda_mo_identity', [
        'company' => '',
        'surname' => '',
        'name' => '',
        'city' => '',
        'street' => '',
        'number' => '',
        'zip' => '',
        'ico' => '',
        'dic' => '',
        'phone' => '',
        'email' => '',
        'www' => '',
    ]);

    // Přidání informací o identitě
    $my_identity = $invoice_header->addChild('inv:myIdentity');
    $address = $my_identity->addChild('typ:address');
    $address->addChild('typ:company', $identity['company']);
    $address->addChild('typ:surname', $identity['surname']);
    $address->addChild('typ:name', $identity['name']);
    $address->addChild('typ:city', $identity['city']);
    $address->addChild('typ:street', $identity['street']);
    $address->addChild('typ:number', $identity['number']);
    $address->addChild('typ:zip', $identity['zip']);
    $address->addChild('typ:ico', $identity['ico']);
    $address->addChild('typ:dic', $identity['dic']);
    $address->addChild('typ:phone', $identity['phone']);
    $address->addChild('typ:email', $identity['email']);
    $address->addChild('typ:www', $identity['www']);
            
// Aktualizace logu
            $log = get_option('pohoda_mo_log', '');
            $log .= date('Y-m-d H:i:s') . ": Vygenerováno " . count($orders) . " objednávek.\n";
            update_option('pohoda_mo_log', $log);
        }
        
        // Přechod na další stránku objednávek
        $page++;
    } while (!empty($orders));

    // Nastavení hlavičky pro stažení XML souboru
    header('Content-Type: text/xml; charset=Windows-1250');
    header('Content-Disposition: attachment; filename="objednavky.xml"');

    // Výpis XML souboru
    echo str_replace('\/', '/', $xml->asXML());
    exit;
}      

function pohoda_mo_admin_page_callback() {
    ?>
    <div class="wrap">
        <h1><?php echo esc_html(get_admin_page_title()); ?></h1>
        <form action="options.php" method="post">
            <?php
            // Výpis nastavení pro stránku 'pohoda-mo'
            settings_fields('pohoda_mo');
            do_settings_sections('pohoda_mo');
            submit_button('Uložit nastavení');
            ?>
        </form>
    </div>
    <?php
    // Add these lines
    ?>
    <form action="" method="post">
        <input type="hidden" name="pohoda_mo_generate_data" value="1">
        <?php submit_button('Načíst data z WooCommerce API'); ?>
    </form>
    <form action="" method="post">
        <input type="hidden" name="pohoda_mo_generate_xml" value="1">
        <?php submit_button('Generovat XML'); ?>
    </form>
    <!-- End of added lines -->
    <h2>Log generování</h2>
    <pre>
    <?php   
    $log = get_option('pohoda_mo_log', '');
    $log_lines = explode("\n", $log); // Rozdělíme log na jednotlivé řádky
    $log_lines = array_reverse($log_lines); // Obrátíme pořadí řádků
    $log_lines = array_slice($log_lines, 0, 50); // Omezíme počet řádků na 50
    $log = implode("\n", $log_lines); // Spojujeme řádky zpět do jednoho řetězce
    echo esc_html($log); // Zobrazíme aktualizovaný log
    ?>
    </pre>
    </div>
    <?php
} // Zde by měla být konečná závorka funkce
?>





// Funkce zpětného volání pro výpis pole pro zadání informací o identitě
function pohoda_mo_identity_callback() {
    // Získání uložených hodnot
    $value = get_option('pohoda_mo_identity', [
        'company' => 'Eva Kozáková - Antikvariát',
        'surname' => 'Kozáková',
        'name' => 'Eva',
        'city' => 'Praha 7',
        'street' => 'Letohradská',
        'number' => '56',
        'zip' => '170 00',
        'ico' => '13107011',
        'dic' => 'CZ485821084',
        'phone' => '224 917 862',
        'email' => 'antikvariat-kozakova@seznam.cz',
        'www' => 'www.antikvariat-ucebnice.cz',
    ]);
    ?>
    <label>
        Název společnosti:
        <input type="text" name="pohoda_mo_identity[company]" value="<?php echo esc_attr($value['company']); ?>">
    </label><br>
    <label>
        Příjmení:
        <input type="text" name="pohoda_mo_identity[surname]" value="<?php echo esc_attr($value['surname']); ?>">
    </label><br>
    <label>
        Jméno:
        <input type="text" name="pohoda_mo_identity[name]" value="<?php echo esc_attr($value['name']); ?>">
    </label><br>
    <label>
        Město:
        <input type="text" name="pohoda_mo_identity[city]" value="<?php echo esc_attr($value['city']); ?>">
    </label><br>
    <label>
        Ulice:
        <input type="text" name="pohoda_mo_identity[street]" value="<?php echo esc_attr($value['street']); ?>">
    </label><br>
    <label>
        Číslo:
        <input type="text" name="pohoda_mo_identity[number]" value="<?php echo esc_attr($value['number']); ?>">
    </label><br>
    <label>
        PSČ:
        <input type="text" name="pohoda_mo_identity[zip]" value="<?php echo esc_attr($value['zip']); ?>">
    </label><br>
    <label>
        IČO:
        <input type="text" name="pohoda_mo_identity[ico]" value="<?php echo esc_attr($value['ico']); ?>">
    </label><br>
    <label>
        DIČ:
        <input type="text" name="pohoda_mo_identity[dic]" value="<?php echo esc_attr($value['dic']); ?>">
    </label><br>
    <label>
        Telefon:
        <input type="text" name="pohoda_mo_identity[phone]" value="<?php echo esc_attr($value['phone']); ?>">
    </label><br>
    <label>
        Email:
        <input type="text" name="pohoda_mo_identity[email]" value="<?php echo esc_attr($value['email']); ?>">
    </label><br>
    <label>
        WWW:
        <input type="text" name="pohoda_mo_identity[www]" value="<?php echo esc_attr($value['www']); ?>">
    </label><br>
    
   <?php
} // Zde by měla být konečná závorka funkce


// Funkce zpětného volání pro výpis pole pro zadání časového rozmezí
function pohoda_mo_date_range_callback() {
    // Získání uložených hodnot
    $value = get_option('pohoda_mo_date_range', ['start' => '', 'end' => '']);
    ?>
    <label>
        Počáteční datum:
        <input type="date" name="pohoda_mo_date_range[start]" value="<?php echo esc_attr($value['start']); ?>">
    </label>
    <label>
        Koncové datum:
        <input type="date" name="pohoda_mo_date_range[end]" value="<?php echo esc_attr($value['end']); ?>">
    </label>
   <?php
} // Zde by měla být konečná závorka funkce
?>

// Funkce zpětného volání pro výpis pole pro výběr stavů objednávek
function pohoda_mo_order_statuses_callback() {
    // Získání uložených hodnot
    $values = get_option('pohoda_mo_order_statuses', []);
    // Získání všech možných stavů objednávek
    $statuses = wc_get_order_statuses();
    foreach($statuses as $status => $name) {
        ?>
        <input type="checkbox" name="pohoda_mo_order_statuses[]" value="<?php echo esc_attr($status); ?>" <?php checked(in_array($status, $values)); ?>> <?php echo esc_html($name); ?><br>
        <?php
    }
    error_log(print_r($values, true));
}


function change( $data ) {
    $message = null;
    $type = null;
    if ( null != $data ) {
        if ( false === get_option( 'myOption' ) ) {
            add_option( 'myOption', $data );
            $type = 'updated';
            $message = __( 'Successfully saved', 'my-text-domain' );
        } else {
            update_option( 'myOption', $data );
            $type = 'updated';
            $message = __( 'Successfully updated', 'my-text-domain' );
        }
    } else {
        $type = 'error';
        $message = __( 'Data can not be empty', 'my-text-domain' );
    }
    add_settings_error(
        'myUniqueIdentifyer',
        esc_attr( 'settings_updated' ),
        $message,
        $type
    );
}

