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

/** Photo slots for the Open Shop / check-in wizard. */
function avogs_checkin_photo_slots()
{
    return array(
        array(
            'key' => 'shop_opening',
            'label' => 'Opening shop / taking over',
            'required' => false,
        ),
        array(
            'key' => 'juice_station',
            'label' => 'Juice & smoothie station setup',
            'required' => false,
        ),
        array(
            'key' => 'arrangement',
            'label' => 'Arranging of items',
            'required' => false,
        ),
    );
}

/** Photo slots for the Close Shop / check-out wizard. */
function avogs_checkout_photo_slots()
{
    return array(
        array(
            'key' => 'shop_closed',
            'label' => 'Shop closed / locked up',
            'required' => false,
        ),
        array(
            'key' => 'cash_count',
            'label' => 'Cash count',
            'required' => false,
        ),
        array(
            'key' => 'stock_remaining',
            'label' => 'Remaining stock',
            'required' => false,
        ),
    );
}

function avogs_checkout_photo_slot_label($slot)
{
    static $labels = array(
        'shop_closed'       => 'Shop closed / locked up',
        'cash_count'        => 'Cash count',
        'stock_remaining'   => 'Remaining stock',
    );
    if (isset($labels[$slot])) {
        return $labels[$slot];
    }
    return avogs_photo_slot_label($slot);
}

/** Normalise checkout photos (same shapes as check-in). */
function avogs_normalize_checkout_photos($photos, $photoIds = null)
{
    $out = avogs_normalize_checkin_photos($photos, $photoIds);
    if (empty($out) && is_array($photoIds)) {
        return avogs_normalize_checkin_photos(array(), $photoIds);
    }
    return $out;
}

function avogs_resolve_checkout_photos($photosMap)
{
    $details = array();
    $missing = array();
    if (!is_array($photosMap)) {
        return array($details, $missing);
    }
    foreach ($photosMap as $slot => $uploadId) {
        $uploadId = trim((string) $uploadId);
        if ($uploadId === '') {
            continue;
        }
        $row = Db::row("SELECT upload_id, url FROM " . Db::t('uploads') . "
            WHERE upload_id = " . Db::escRaw($uploadId));
        if (!$row) {
            $missing[] = array('slot' => $slot, 'upload_id' => $uploadId);
            $details[] = array(
                'slot' => $slot,
                'label' => avogs_checkout_photo_slot_label($slot),
                'upload_id' => $uploadId,
                'url' => null,
                'status' => 'missing',
            );
            continue;
        }
        $details[] = array(
            'slot' => $slot,
            'label' => avogs_checkout_photo_slot_label($slot),
            'upload_id' => $row['upload_id'],
            'url' => $row['url'],
            'status' => 'ok',
        );
    }
    return array($details, $missing);
}

function avogs_photo_slot_label($slot)
{
    static $labels = array(
        'shop_opening'  => 'Opening shop / taking over',
        'juice_station' => 'Juice & smoothie station setup',
        'arrangement'   => 'Arranging of items',
    );
    if (isset($labels[$slot])) {
        return $labels[$slot];
    }
    return ucwords(str_replace('_', ' ', $slot));
}

function avogs_json_decode($raw)
{
    if ($raw === null || $raw === '') {
        return null;
    }
    if (is_array($raw)) {
        return $raw;
    }
    $decoded = json_decode($raw, true);
    if (is_array($decoded)) {
        return $decoded;
    }
    $fixed = html_entity_decode($raw, ENT_QUOTES, 'UTF-8');
    $decoded = json_decode($fixed, true);
    return is_array($decoded) ? $decoded : null;
}

function avogs_normalize_checkin_photos($photos, $photoIds = null)
{
    $out = array();

    if (is_string($photos)) {
        $photos = avogs_json_decode($photos);
    }

    if (is_array($photos)) {
        $isList = array_keys($photos) === range(0, count($photos) - 1);
        if ($isList) {
            foreach ($photos as $entry) {
                if (!is_array($entry)) {
                    continue;
                }
                $slot = null;
                foreach (array('slot', 'key', 'name', 'photo_slot') as $k) {
                    if (!empty($entry[$k])) {
                        $slot = $entry[$k];
                        break;
                    }
                }
                $uid = null;
                foreach (array('upload_id', 'id', 'uploadId', 'photo_id') as $k) {
                    if (!empty($entry[$k])) {
                        $uid = $entry[$k];
                        break;
                    }
                }
                if ($slot && $uid) {
                    $out[$slot] = trim((string) $uid);
                }
            }
        } else {
            foreach ($photos as $slot => $val) {
                if (is_array($val)) {
                    foreach (array('upload_id', 'id', 'uploadId') as $k) {
                        if (!empty($val[$k])) {
                            $out[$slot] = trim((string) $val[$k]);
                            break;
                        }
                    }
                } elseif (is_string($val) && trim($val) !== '') {
                    $out[$slot] = trim($val);
                }
            }
        }
    }

    if (empty($out) && is_array($photoIds)) {
        $slots = avogs_checkin_photo_slots();
        $i = 0;
        foreach ($photoIds as $val) {
            if (is_array($val)) {
                continue;
            }
            $uid = trim((string) $val);
            if ($uid === '') {
                continue;
            }
            $slot = isset($slots[$i]['key']) ? $slots[$i]['key'] : ('photo_' . ($i + 1));
            $out[$slot] = $uid;
            $i++;
        }
    }

    return $out;
}

function avogs_resolve_photos($photosMap)
{
    $details = array();
    $missing = array();
    if (!is_array($photosMap)) {
        return array($details, $missing);
    }
    foreach ($photosMap as $slot => $uploadId) {
        $uploadId = trim((string) $uploadId);
        if ($uploadId === '') {
            continue;
        }
        $row = Db::row("SELECT upload_id, url FROM " . Db::t('uploads') . "
            WHERE upload_id = " . Db::escRaw($uploadId));
        if (!$row) {
            $missing[] = array('slot' => $slot, 'upload_id' => $uploadId);
            $details[] = array(
                'slot' => $slot,
                'label' => avogs_photo_slot_label($slot),
                'upload_id' => $uploadId,
                'url' => null,
                'status' => 'missing',
            );
            continue;
        }
        $details[] = array(
            'slot' => $slot,
            'label' => avogs_photo_slot_label($slot),
            'upload_id' => $row['upload_id'],
            'url' => $row['url'],
            'status' => 'ok',
        );
    }
    return array($details, $missing);
}
