<?php
/** Shared helpers for the controllers. */

/** Map an FA-style stock_id to a report category. */
function avogs_category_of($stockId)
{
    if (strpos($stockId, 'AVO-') === 0) return 'avocado';
    if (strpos($stockId, 'HNY-') === 0) return 'honey';
    if (strpos($stockId, 'BVG-') === 0) return 'beverage';
    return 'other';
}

/**
 * Derive the app's catalogue group + index from an FA stock_id, matching the
 * Flutter app's group ordering (retail/wholesale S1..S7, honey 250/450/900,
 * beverage juice/smoothie/ginger).
 */
function avogs_group_index($stockId)
{
    $sizeIdx = array('S1' => 0, 'S2' => 1, 'S3' => 2, 'S4' => 3, 'S5' => 4, 'S6' => 5, 'S7' => 6);
    $honeyIdx = array('250G' => 0, '450G' => 1, '900G' => 2);
    $bevIdx = array('JUICE' => 0, 'SMOOTHIE' => 1, 'GINGER' => 2);

    if (strpos($stockId, 'AVO-RT-') === 0) {
        $k = substr($stockId, 7);
        return array('group' => 'retail', 'index' => isset($sizeIdx[$k]) ? $sizeIdx[$k] : 0);
    }
    if (strpos($stockId, 'AVO-WS-') === 0) {
        $k = substr($stockId, 7);
        return array('group' => 'wholesale', 'index' => isset($sizeIdx[$k]) ? $sizeIdx[$k] : 0);
    }
    if (strpos($stockId, 'HNY-') === 0) {
        $k = substr($stockId, 4);
        return array('group' => 'honey', 'index' => isset($honeyIdx[$k]) ? $honeyIdx[$k] : 0);
    }
    if (strpos($stockId, 'BVG-') === 0) {
        $k = substr($stockId, 4);
        return array('group' => 'beverage', 'index' => isset($bevIdx[$k]) ? $bevIdx[$k] : 0);
    }
    return array('group' => 'other', 'index' => 0);
}

function avogs_unit_for($group)
{
    if ($group === 'honey') return 'jar';
    if ($group === 'beverage') return 'btl';
    return 'pc';
}

/** Resolve the store code for a request (query/body, else token, else first store). */
function avogs_store(Request $req, $auth = null)
{
    $store = $req->q('store', $req->input('store', null));
    if (!$store && $auth && !empty($auth['store_code'])) {
        $store = $auth['store_code'];
    }
    if (!$store) {
        $store = Db::val("SELECT loc_code FROM " . TB_PREF . "locations WHERE inactive = 0 ORDER BY loc_code LIMIT 1");
    }
    return $store;
}

/** YYYY-MM-DD for a request 'date' (query or body), default today. */
function avogs_date(Request $req)
{
    $d = $req->q('date', $req->input('date', null));
    if ($d && preg_match('/^\d{4}-\d{2}-\d{2}$/', $d)) {
        return $d;
    }
    return date('Y-m-d');
}

/** Default expected handover figures when no prior shift exists. */
function avogs_default_expected()
{
    return array(
        'avocado' => 100, 'till' => 2000, 'float' => 500,
        'juice' => 20, 'smoothie' => 10, 'ginger' => 10,
        'honey_250' => 10, 'honey_450' => 8, 'honey_900' => 5,
    );
}

/** Checklist templates (ported from the Flutter app's checklists.dart). */
function avogs_checklist_template($mode)
{
    $T = array();
    $T['morning-open'] = array(
        'title' => 'Morning Opening',
        'sub' => 'Confirm stock & cash, then set up the shop',
        'sections' => array(
            array('title' => 'Opening Stock Confirmation', 'items' => array(
                array('label' => 'Confirm Opening Stock', 'special' => 'stock'),
            )),
            array('title' => 'Cash Confirmation', 'items' => array(
                array('label' => 'Confirm Opening Cash', 'special' => 'cash'),
            )),
            array('title' => 'Shop Setup', 'items' => array(
                array('label' => 'Arrived & Opened shop on time'),
                array('label' => 'Setup Juice & Smoothie Station'),
                array('label' => 'Arranged Shelves & Avocado Display'),
                array('label' => 'Made morning calls and deliveries', 'input' => array('type' => 'text', 'placeholder' => 'Note the calls made & deliveries planned')),
                array('label' => 'Checked pending orders', 'input' => array('type' => 'text', 'placeholder' => 'Notes on pending orders')),
            )),
        ),
    );
    $T['evening-open'] = array(
        'title' => 'Evening Opening',
        'sub' => 'Confirm handover stock & cash, then set up for the evening',
        'sections' => array(
            array('title' => 'Opening Stock Confirmation', 'items' => array(
                array('label' => 'Confirm Opening Stock', 'special' => 'stock-ev'),
            )),
            array('title' => 'Cash Confirmation', 'items' => array(
                array('label' => 'Confirm Opening Cash', 'special' => 'cash-ev'),
            )),
            array('title' => 'Shop Setup', 'items' => array(
                array('label' => 'Took over shop on time'),
                array('label' => 'Setup Juice & Smoothie Station'),
                array('label' => 'Arranged ripe avocados & shelves'),
                array('label' => 'Made evening calls and deliveries', 'input' => array('type' => 'text', 'placeholder' => 'Note the calls made & deliveries planned')),
                array('label' => 'Checked pending orders', 'input' => array('type' => 'text', 'placeholder' => 'Notes on pending orders')),
            )),
        ),
    );
    $T['morning-close'] = array(
        'title' => 'Morning Closing',
        'sub' => 'Count all remaining stock & prepare handover for evening',
        'sections' => array(
            array('title' => 'Avocado Stock', 'items' => array(
                array('label' => 'Counted remaining avocado stock', 'input' => array('type' => 'number', 'placeholder' => 'Remaining pieces')),
                array('label' => 'Counted ripening avocados', 'input' => array('type' => 'number', 'placeholder' => 'Pieces set aside to ripen')),
                array('label' => 'Logged wastage for this shift', 'input' => array('type' => 'text', 'placeholder' => 'e.g. 5 overripe')),
            )),
            array('title' => 'Cash Count', 'items' => array(
                array('label' => 'Total sales this shift', 'input' => array('type' => 'auto')),
                array('label' => 'Counted all cash collected', 'input' => array('type' => 'number', 'placeholder' => 'Total cash in hand (KSh)')),
                array('label' => 'Set float for evening shift', 'input' => array('type' => 'number', 'placeholder' => 'Float for evening shift (KSh)')),
                array('label' => 'Set till for evening shift', 'input' => array('type' => 'number', 'placeholder' => 'Till for evening shift (KSh)')),
            )),
        ),
    );
    $T['evening-close'] = array(
        'title' => 'Evening Closing',
        'sub' => 'Close the shop & prepare for tomorrow morning',
        'sections' => array(
            array('title' => 'Avocado Stock', 'items' => array(
                array('label' => 'Counted remaining avocado stock', 'input' => array('type' => 'number', 'placeholder' => 'Remaining pieces')),
                array('label' => 'Avocados set to ripen overnight', 'input' => array('type' => 'number', 'placeholder' => 'Pieces ripening')),
            )),
            array('title' => 'Cash & Close', 'items' => array(
                array('label' => 'Total sales today', 'input' => array('type' => 'auto')),
                array('label' => 'Counted all cash collected today', 'input' => array('type' => 'number', 'placeholder' => 'Total cash in hand (KSh)')),
                array('label' => 'Set tomorrow morning float', 'input' => array('type' => 'number', 'placeholder' => 'Float for tomorrow morning (KSh)')),
                array('label' => 'Set tomorrow morning till', 'input' => array('type' => 'number', 'placeholder' => 'Till for tomorrow morning (KSh)')),
                array('label' => 'Locked up shop'),
            )),
        ),
    );
    return isset($T[$mode]) ? $T[$mode] : null;
}
