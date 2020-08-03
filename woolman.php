<?php

// CiviCRM contact_id of Woolman
define('WOOLMAN', 243);

// Country ID of United States
define('USA', 1228);

/**
 * Implements hook_civicrm_tokens().
 */
function woolman_civicrm_tokens(&$tokens) {
  $tokens['date'] = [
    'date.date_short' => 'Today\'s Date: dd/mm/yyyy',
    'date.date_med' => 'Today\'s Date: Mon d yyyy',
    'date.date_long' => 'Today\'s Date: Month dth, yyyy',
  ];
  $tokens['contact']['contact.address_format'] = 'Address (Full)';
  $tokens['donor'] = [
    'donor.unthanked' => 'Donations: To Thank',
    'donor.set_thank_you' => 'Donations: UPDATE unthanked (hidden)',
    'donor.clear_thank_you' => 'Donations: CLEAR thanked today (hidden)',
  ];
}

/**
 * Implements hook_civicrm_tokenValues().
 */
function woolman_civicrm_tokenValues(&$values, $cids, $job = NULL, $tokens = [], $context = NULL) {
  $contacts = implode(',', $cids);
  $tokens += [
    'contact' => [],
  ];

  // Bug in CiviCRM displays empty options as a long string of zeroes
  $sp = CRM_Core_DAO::VALUE_SEPARATOR;
  foreach ($values as $key => $vals) {
    foreach ($vals as $k => $v) {
      if (is_string($v) && strpos($v, $sp . '0' . $sp) !== FALSE) {
        $values[$key][$k] = '';
      }
    }
  }

  // Date tokens
  if (!empty($tokens['date'])) {
    $date = [
      'date.date_short' => date('m/d/Y'),
      'date.date_med' => date('M j Y'),
      'date.date_long' => date('F jS, Y'),
    ];
    foreach ($cids as $cid) {
      $values[$cid] = empty($values[$cid]) ? $date : $values[$cid] + $date;
    }
  }

  // Fill first name and nick name with default values
  if (in_array('first_name', $tokens['contact']) || in_array('nick_name', $tokens['contact'])) {
    $dao = CRM_Core_DAO::executeQuery("
      SELECT first_name, nick_name, contact_type, id
      FROM civicrm_contact
      WHERE id IN ($contacts)"
    );
    while ($dao->fetch()) {
      $cid = $dao->id;
      if (!($values[$cid]['first_name'] = $dao->first_name)) {
        $values[$cid]['first_name'] = $dao->contact_type == 'Individual' ? 'Friend' : 'Friends';
      }
      if (empty($values[$cid]['nick_name']) || $dao->contact_type != 'Individual') {
        $values[$cid]['nick_name'] = $values[$cid]['first_name'];
      }
    }
  }

  // Format birth dates
  if (in_array('birth_date', $tokens['contact'])) {
    foreach ($values as $k => $v) {
      if (!empty($v['birth_date']) && $bd = strtotime($v['birth_date'])) {
        $values[$k]['birth_date'] = date('m/d/Y', $bd);
      }
    }
  }

  // Formatted address token
  if (in_array('address_format', $tokens['contact'])) {
    $dao = CRM_Core_DAO::executeQuery('
      SELECT *
      FROM civicrm_address
      WHERE contact_id IN (' . $contacts . ') AND is_primary = 1'
    );
    while ($dao->fetch()) {
      $values[$dao->contact_id]['contact.address_format'] = _woolman_format_address($dao);
    }
  }

  // Dontation info
  if (!empty($tokens['donor'])) {
    $spouses = [];
    $contacts_and_spouses = $cids;
    $dao = CRM_Core_DAO::executeQuery("
      SELECT contact_id_a, contact_id_b
      FROM civicrm_relationship
      WHERE relationship_type_id = 2
      AND is_active = 1
      AND (end_date IS NULL OR end_date > CURDATE())
      AND (contact_id_a IN ($contacts) OR contact_id_b IN ($contacts))
    ");
    while ($dao->fetch()) {
      if (!in_array($dao->contact_id_a, $contacts_and_spouses)) {
        $contacts_and_spouses[] = $dao->contact_id_a;
      }
      if (!in_array($dao->contact_id_b, $contacts_and_spouses)) {
        $contacts_and_spouses[] = $dao->contact_id_b;
      }
      if (in_array($dao->contact_id_a, $cids)) {
        $spouses[$dao->contact_id_b] = $dao->contact_id_a;
      }
      if (in_array($dao->contact_id_b, $cids)) {
        $spouses[$dao->contact_id_a] = $dao->contact_id_b;
      }
    }
    $contacts_and_spouses = implode(',', $contacts_and_spouses);
    // Clear thank-yous from past 48hrs (a kind of crude UNDO)
    if (in_array('clear_thank_you', $tokens['donor'])) {
      CRM_Core_DAO::executeQuery("
        UPDATE civicrm_contribution SET thankyou_date = NULL
        WHERE is_test = 0 AND financial_type_id IN (1,9) AND contribution_status_id = 1
        AND DATE(thankyou_date) + INTERVAL 2 DAY > CURDATE() AND contact_id IN ($contacts_and_spouses)"
      );
    }
    if (in_array('unthanked', $tokens['donor'])) {
      $dao = CRM_Core_DAO::executeQuery("
        SELECT
          cc.contact_id, cc.total_amount, cc.receive_date, cc.check_number, cc.financial_type_id,
          con.display_name,
          hon.display_name as honoree,
          ac.label AS account,
          pi.label AS payment_instrument,
          ht.label AS honor_type,
          ca.designation_60 AS designation,
          n.note
        FROM civicrm_contribution cc
        INNER JOIN civicrm_contact con ON con.id = cc.contact_id
        LEFT JOIN civicrm_contribution_soft soft ON soft.contribution_id = cc.id and soft.soft_credit_type_id IN (1,2)
        LEFT JOIN civicrm_contact hon ON hon.id = soft.contact_id
        LEFT JOIN civicrm_value_contribution_accounts_10 ca ON ca.entity_id = cc.id
        LEFT JOIN civicrm_option_value ac ON ca.account_18 = ac.value AND ac.option_group_id = 103
        LEFT JOIN civicrm_option_value pi ON cc.payment_instrument_id = pi.value AND pi.option_group_id = (SELECT id FROM civicrm_option_group WHERE name = 'payment_instrument')
        LEFT JOIN civicrm_option_value ht ON soft.soft_credit_type_id = ht.value AND ht.option_group_id = (SELECT id FROM civicrm_option_group WHERE name = 'soft_credit_type')
        LEFT JOIN civicrm_note n ON n.entity_table = 'civicrm_contribution' AND n.entity_id = cc.id
        WHERE cc.is_test = 0 AND cc.financial_type_id IN (1,9) AND cc.contribution_status_id = 1
          AND cc.contact_id IN ($contacts_and_spouses) AND cc.thankyou_date IS NULL
        ORDER BY cc.receive_date"
      );
      $header = '
        <table class="donations" style="border-collapse:collapse; width: 100%; text-align:left;">
          <thead><tr style="text-align:left;">
            <th>Date</th>
            <th>Donor</th>
            <th>Amount</th>
            <th>Paid By</th>
            <th>Given for</th>
          </tr></thead>
          <tbody>';
      $td = '<td style="border: 1px solid black;">';
      while ($dao->fetch()) {
        $cid = $dao->contact_id;
        $row = '
          <tr>' .
          $td . date('m/d/Y', strtotime($dao->receive_date)) . '</td>' .
          $td . $dao->display_name . '</td>' .
          $td . '$' . number_format($dao->total_amount, 2) . '</td>' .
          $td . ($dao->payment_instrument ? $dao->payment_instrument : 'In Kind')
          . ($dao->check_number ? ' #' . $dao->check_number : '') . '</td>' .
          $td . ($dao->account ? ($dao->designation ? $dao->designation : $dao->account) : $dao->note) . ($dao->honoree ? "<br />{$dao->honor_type} {$dao->honoree}" : '') . '</td>
          </tr>';
        if (in_array($cid, $cids)) {
          $values[$cid]['donor.unthanked'] = ($values[$cid]['donor.unthanked'] ?? $header) . $row;
        }
        if (isset($spouses[$cid])) {
          $values[$spouses[$cid]]['donor.unthanked'] = ($values[$spouses[$cid]]['donor.unthanked'] ?? $header) . $row;
        }
      }
      foreach ($cids as $cid) {
        if (!empty($values[$cid]['donor.unthanked'])) {
          $values[$cid]['donor.unthanked'] .= '</tbody></table>';
        }
      }
    }
    if (in_array('set_thank_you', $tokens['donor'])) {
      CRM_Core_DAO::executeQuery("
        UPDATE civicrm_contribution SET thankyou_date = NOW()
        WHERE is_test = 0 AND financial_type_id IN (1,9) AND contribution_status_id = 1
        AND contact_id IN ($contacts_and_spouses) AND thankyou_date IS NULL"
      );
    }
  }
}

/**
 * Formats a multi-line address.
 */
function _woolman_format_address($obj, $sep = "<br />\n") {
  $obj = (object) $obj;
  $address = '';
  if (!empty($obj->street_address) && $address = trim($obj->street_address)) {
    $address .= $sep;
  }
  if (!empty($obj->supplemental_address_1) && $add = trim($obj->supplemental_address_1)) {
    $address .= $add . $sep;
  }
  if (!empty($obj->supplemental_address_2) && $add = trim($obj->supplemental_address_2)) {
    $address .= $add . $sep;
  }
  if (!empty($obj->city) && $add = trim($obj->city)) {
    $address .= $add . (!empty($obj->state_province_id) ? ', ' : '');
  }
  if (!empty($obj->state_province_id)) {
    $address .= CRM_Core_PseudoConstant::stateProvinceAbbreviation($obj->state_province_id);
  }
  if (!empty($obj->postal_code) && $add = trim($obj->postal_code)) {
    $address .= ' ' . $add . (empty($obj->postal_code_suffix) ? '' : '-' . $obj->postal_code_suffix);
  }
  if (!empty($obj->country_id) && $obj->country_id != USA) {
    $address .= $sep . CRM_Core_PseudoConstant::country($obj->country_id);
  }
  return $address;
}
