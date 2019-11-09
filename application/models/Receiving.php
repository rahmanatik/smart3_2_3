<?php if (!defined('BASEPATH')) exit('No direct script access allowed');

/**
 * Receiving class
 */

class Receiving extends CI_Model
{
	public function get_info($receiving_id)
	{
		$this->db->from('receivings');
		$this->db->join('people', 'people.person_id = receivings.supplier_id', 'LEFT');
		$this->db->join('suppliers', 'suppliers.person_id = receivings.supplier_id', 'LEFT');
		$this->db->where('receiving_id', $receiving_id);

		return $this->db->get();
	}

	public function get_receiving_by_reference($reference)
	{
		$this->db->from('receivings');
		$this->db->where('reference', $reference);

		return $this->db->get();
	}

	public function is_valid_receipt($receipt_receiving_id)
	{
		if(!empty($receipt_receiving_id))
		{
			//RECV #
			$pieces = explode(' ', $receipt_receiving_id);

			if(count($pieces) == 2 && preg_match('/(RECV|KIT)/', $pieces[0]))
			{
				return $this->exists($pieces[1]);
			}
			else
			{
				return $this->get_receiving_by_reference($receipt_receiving_id)->num_rows() > 0;
			}
		}

		return FALSE;
	}

	public function exists($receiving_id)
	{
		$this->db->from('receivings');
		$this->db->where('receiving_id', $receiving_id);

		return ($this->db->get()->num_rows() == 1);
	}

	public function update($receiving_data, $receiving_id)
	{
		$this->db->where('receiving_id', $receiving_id);

		return $this->db->update('receivings', $receiving_data);
	}

	public function save($items, $supplier_id, $employee_id, $comment, $reference, $payment_type, $receiving_id = FALSE)
	{
		if(count($items) == 0)
		{
			return -1;
		}

		$receivings_data = array(
			'receiving_time' => date('Y-m-d H:i:s'),
			'supplier_id' => $this->Supplier->exists($supplier_id) ? $supplier_id : NULL,
			'employee_id' => $employee_id,
			'payment_type' => $payment_type,
			'comment' => $comment,
			'reference' => $reference
		);

		//Run these queries as a transaction, we want to make sure we do all or nothing
		$this->db->trans_start();

		$this->db->insert('receivings', $receivings_data);
		$receiving_id = $this->db->insert_id();

		foreach($items as $line=>$item)
		{
			$cur_item_info = $this->Item->get_info($item['item_id']);

			$receivings_items_data = array(
				'receiving_id' => $receiving_id,
				'item_id' => $item['item_id'],
				'line' => $item['line'],
				'description' => $item['description'],
				'serialnumber' => $item['serialnumber'],
				'quantity_purchased' => $item['quantity'],
				'receiving_quantity' => $item['receiving_quantity'],
				'discount_percent' => $item['discount'],
				'item_cost_price' => $cur_item_info->cost_price,
				'item_unit_price' => $item['price'],
				'item_location' => $item['item_location']
			);

			$this->db->insert('receivings_items', $receivings_items_data);

			$items_received = $item['receiving_quantity'] != 0 ? $item['quantity'] * $item['receiving_quantity'] : $item['quantity'];

			// update cost price, if changed AND is set in config as wanted
			if($cur_item_info->cost_price != $item['price'] && $this->config->item('receiving_calculate_average_price') != FALSE)
			{
				$this->Item->change_cost_price($item['item_id'], $items_received, $item['price'], $cur_item_info->cost_price);
			}

			//Update stock quantity
			$item_quantity = $this->Item_quantity->get_item_quantity($item['item_id'], $item['item_location']);
			$this->Item_quantity->save(array('quantity' => $item_quantity->quantity + $items_received, 'item_id' => $item['item_id'],
											  'location_id' => $item['item_location']), $item['item_id'], $item['item_location']);

			$recv_remarks = 'RECV ' . $receiving_id;
			$inv_data = array(
				'trans_date' => date('Y-m-d H:i:s'),
				'trans_items' => $item['item_id'],
				'trans_user' => $employee_id,
				'trans_location' => $item['item_location'],
				'trans_comment' => $recv_remarks,
				'trans_inventory' => $items_received
			);

			$this->Inventory->insert($inv_data);

			$supplier = $this->Supplier->get_info($supplier_id);
		}

		$this->db->trans_complete();

		if($this->db->trans_status() === FALSE)
		{
			return -1;
		}

		return $receiving_id;
	}

	public function delete_list($receiving_ids, $employee_id, $update_inventory = TRUE)
	{
		$success = TRUE;

		// start a transaction to assure data integrity
		$this->db->trans_start();

		foreach($receiving_ids as $receiving_id)
		{
			$success &= $this->delete($receiving_id, $employee_id, $update_inventory);
		}

		// execute transaction
		$this->db->trans_complete();

		$success &= $this->db->trans_status();

		return $success;
	}

	public function delete($receiving_id, $employee_id, $update_inventory = TRUE)
	{
		// start a transaction to assure data integrity
		$this->db->trans_start();

		if($update_inventory)
		{
			// defect, not all item deletions will be undone??
			// get array with all the items involved in the sale to update the inventory tracking
			$items = $this->get_receiving_items($receiving_id)->result_array();
			foreach($items as $item)
			{
				// create query to update inventory tracking
				$inv_data = array(
					'trans_date' => date('Y-m-d H:i:s'),
					'trans_items' => $item['item_id'],
					'trans_user' => $employee_id,
					'trans_comment' => 'Deleting receiving ' . $receiving_id,
					'trans_location' => $item['item_location'],
					'trans_inventory' => $item['quantity_purchased'] * -1
				);
				// update inventory
				$this->Inventory->insert($inv_data);

				// update quantities
				$this->Item_quantity->change_quantity($item['item_id'], $item['item_location'], $item['quantity_purchased'] * -1);
			}
		}

		// delete all items
		$this->db->delete('receivings_items', array('receiving_id' => $receiving_id));
		// delete sale itself
		$this->db->delete('receivings', array('receiving_id' => $receiving_id));

		// execute transaction
		$this->db->trans_complete();
	
		return $this->db->trans_status();
	}

	public function get_receiving_items($receiving_id)
	{
		$this->db->from('receivings_items');
		$this->db->where('receiving_id', $receiving_id);

		return $this->db->get();
	}
	
	public function get_supplier($receiving_id)
	{
		$this->db->from('receivings');
		$this->db->where('receiving_id', $receiving_id);

		return $this->Supplier->get_info($this->db->get()->row()->supplier_id);
	}

	public function get_payment_options()
	{
		return array(
			$this->lang->line('sales_cash') => $this->lang->line('sales_cash'),
			$this->lang->line('sales_check') => $this->lang->line('sales_check'),
			$this->lang->line('sales_debit') => $this->lang->line('sales_debit'),
			$this->lang->line('sales_credit') => $this->lang->line('sales_credit')
		);
	}

	/*
	We create a temp table that allows us to do easy report/receiving queries
	*/
	public function create_temp_table(array $inputs)
	{
		if(empty($inputs['receiving_id']))
		{
			if(empty($this->config->item('date_or_time_format')))
			{
				$where = 'WHERE DATE(receiving_time) BETWEEN ' . $this->db->escape($inputs['start_date']) . ' AND ' . $this->db->escape($inputs['end_date']);
			}
			else
			{
				$where = 'WHERE receiving_time BETWEEN ' . $this->db->escape(rawurldecode($inputs['start_date'])) . ' AND ' . $this->db->escape(rawurldecode($inputs['end_date']));
			}
		}
		else
		{
			$where = 'WHERE receivings_items.receiving_id = ' . $this->db->escape($inputs['receiving_id']);
		}

		$this->db->query('CREATE TEMPORARY TABLE IF NOT EXISTS ' . $this->db->dbprefix('receivings_items_temp') .
			' (INDEX(receiving_date), INDEX(receiving_time), INDEX(receiving_id))
			(
				SELECT 
					MAX(DATE(receiving_time)) AS receiving_date,
					MAX(receiving_time) AS receiving_time,
					receivings_items.receiving_id,
					MAX(comment) AS comment,
					MAX(item_location) AS item_location,
					MAX(reference) AS reference,
					MAX(payment_type) AS payment_type,
					MAX(employee_id) AS employee_id, 
					items.item_id,
					MAX(receivings.supplier_id) AS supplier_id,
					MAX(quantity_purchased) AS quantity_purchased,
					MAX(receivings_items.receiving_quantity) AS receiving_quantity,
					MAX(item_cost_price) AS item_cost_price,
					MAX(item_unit_price) AS item_unit_price,
					MAX(discount_percent) AS discount_percent,
					receivings_items.line,
					MAX(serialnumber) AS serialnumber,
					MAX(receivings_items.description) AS description,
					MAX(item_unit_price * quantity_purchased * receivings_items.receiving_quantity - item_unit_price * quantity_purchased * receivings_items.receiving_quantity * discount_percent / 100) AS subtotal,
					MAX(item_unit_price * quantity_purchased * receivings_items.receiving_quantity - item_unit_price * quantity_purchased * receivings_items.receiving_quantity * discount_percent / 100) AS total,
					MAX((item_unit_price * quantity_purchased * receivings_items.receiving_quantity - item_unit_price * quantity_purchased * receivings_items.receiving_quantity * discount_percent / 100) - (item_cost_price * quantity_purchased)) AS profit,
					MAX(item_cost_price * quantity_purchased * receivings_items.receiving_quantity ) AS cost
				FROM ' . $this->db->dbprefix('receivings_items') . ' AS receivings_items
				INNER JOIN ' . $this->db->dbprefix('receivings') . ' AS receivings
					ON receivings_items.receiving_id = receivings.receiving_id
				INNER JOIN ' . $this->db->dbprefix('items') . ' AS items
					ON receivings_items.item_id = items.item_id
				' . "
				$where
				" . '
				GROUP BY receivings_items.receiving_id, items.item_id, receivings_items.line
			)'
		);
	}

	//For receivings/manage
	/**
     * Get number of rows for the takings (receivings/manage) view
     */
    public function get_found_rows($search, $filters)
    {
        return $this->search($search, $filters, 0, 0, 'receivings.receiving_time', 'desc', TRUE);
    }

    /**
     * Get the sales data for the takings (receivings/manage) view
     */
    public function search($search, $filters, $rows = 0, $limit_from = 0, $sort = 'receivings.receiving_time', $order = 'desc', $count_only = FALSE)
    {
        // Pick up only non-suspended records
        $where = '';

        if(empty($this->config->item('date_or_time_format')))
        {
            $where .= 'DATE(receivings.receiving_time) BETWEEN ' . $this->db->escape($filters['start_date']) . ' AND ' . $this->db->escape($filters['end_date']);
        }
        else
        {
            $where .= 'receivings.receiving_time BETWEEN ' . $this->db->escape(rawurldecode($filters['start_date'])) . ' AND ' . $this->db->escape(rawurldecode($filters['end_date']));
        }

        // NOTE: temporary tables are created to speed up searches due to the fact that they are ortogonal to the main query
        // create a temporary table to contain all the payments per sale item
        $this->db->query('CREATE TEMPORARY TABLE IF NOT EXISTS ' . $this->db->dbprefix('sales_payments_temp') .
            ' (PRIMARY KEY(sale_id), INDEX(sale_id))
            (
                SELECT payments.sale_id AS sale_id,
                    IFNULL(SUM(payments.payment_amount), 0) AS sale_payment_amount,
                    GROUP_CONCAT(CONCAT(payments.payment_type, " ", payments.payment_amount) SEPARATOR ", ") AS payment_type
                FROM ' . $this->db->dbprefix('sales_payments') . ' AS payments
                INNER JOIN ' . $this->db->dbprefix('sales') . ' AS sales
                    ON receivings.sale_id = payments.sale_id
                WHERE ' . $where . '
                GROUP BY sale_id
            )'
        );

        $decimals = totals_decimals();

        $sale_price = 'sales_items.item_unit_price * sales_items.quantity_purchased';// * (1 - sales_items.discount_percent / 100)';
        $sale_cost = 'SUM(sales_items.item_cost_price * sales_items.quantity_purchased)';
        $tax = 'IFNULL(SUM(sales_items_taxes.tax), 0)';

        if($this->config->item('tax_included'))
        {
            $sale_total = 'ROUND(SUM(' . $sale_price . '), ' . $decimals . ')';
            $sale_subtotal = $sale_total . ' - ' . $tax;
        }
        else
        {
            $sale_subtotal = 'ROUND(SUM(' . $sale_price . '), ' . $decimals . ')';
            $sale_total = $sale_subtotal . ' + ' . $tax;
        }

        // create a temporary table to contain all the sum of taxes per sale item
        $this->db->query('CREATE TEMPORARY TABLE IF NOT EXISTS ' . $this->db->dbprefix('sales_items_taxes_temp') .
            ' (INDEX(sale_id), INDEX(item_id))
            (
                SELECT sales_items_taxes.sale_id AS sale_id,
                    sales_items_taxes.item_id AS item_id,
                    sales_items_taxes.line AS line,
                    SUM(sales_items_taxes.item_tax_amount) as tax
                FROM ' . $this->db->dbprefix('sales_items_taxes') . ' AS sales_items_taxes
                INNER JOIN ' . $this->db->dbprefix('sales') . ' AS sales
                    ON receivings.sale_id = sales_items_taxes.sale_id
                INNER JOIN ' . $this->db->dbprefix('sales_items') . ' AS sales_items
                    ON sales_items.sale_id = sales_items_taxes.sale_id AND sales_items.line = sales_items_taxes.line
                WHERE ' . $where . '
                GROUP BY sale_id, item_id, line
            )'
        );

        // get_found_rows case
        if($count_only == TRUE)
        {
            $this->db->select('COUNT(DISTINCT receivings.sale_id) as count');
        }
        else
        {
            $this->db->select('
                    receivings.sale_id AS sale_id,
                    MAX(DATE(receivings.sale_time)) AS sale_date,
                    MAX(receivings.sale_time) AS sale_time,
                    MAX(receivings.invoice_number) AS invoice_number,
                    MAX(receivings.quote_number) AS quote_number,
                    SUM(sales_items.quantity_purchased) AS items_purchased,
                    MAX(CONCAT(customer_p.first_name, " ", customer_p.last_name)) AS customer_name,
                    MAX(suppliers.company_name) AS company_name,
                    ' . "
                    IFNULL($sale_subtotal, $sale_total) AS subtotal,
                    $tax AS tax,
                    IFNULL($sale_total, $sale_subtotal) AS total,
                    $sale_cost AS cost,
                    (IFNULL($sale_subtotal, $sale_total) - $sale_cost) AS profit,
                    IFNULL($sale_total, $sale_subtotal) AS amount_due,
                    MAX(payments.sale_payment_amount) AS amount_tendered,
                    (MAX(payments.sale_payment_amount) - IFNULL($sale_total, $sale_subtotal)) AS change_due,
                    " . '
                    MAX(payments.payment_type) AS payment_type
            ');
        }

        $this->db->from('sales_items AS sales_items');
        $this->db->join('sales AS sales', 'sales_items.sale_id = receivings.sale_id', 'inner');
        $this->db->join('people AS customer_p', 'receivings.customer_id = customer_p.person_id', 'LEFT');
        $this->db->join('suppliers AS customer', 'receivings.customer_id = suppliers.person_id', 'LEFT');
        $this->db->join('sales_payments_temp AS payments', 'receivings.sale_id = payments.sale_id', 'LEFT OUTER');
        $this->db->join('sales_items_taxes_temp AS sales_items_taxes',
            'sales_items.sale_id = sales_items_taxes.sale_id AND sales_items.item_id = sales_items_taxes.item_id AND sales_items.line = sales_items_taxes.line',
            'LEFT OUTER');

        $this->db->where($where);

        if(!empty($search))
        {
            if($filters['is_valid_receipt'] != FALSE)
            {
                $pieces = explode(' ', $search);
                $this->db->where('receivings.sale_id', $pieces[1]);
            }
            else
            {
                $this->db->group_start();
                    // customer last name
                    $this->db->like('customer_p.last_name', $search);
                    // customer first name
                    $this->db->or_like('customer_p.first_name', $search);
                    // customer first and last name
                    $this->db->or_like('CONCAT(customer_p.first_name, " ", customer_p.last_name)', $search);
                    // customer company name
                    $this->db->or_like('suppliers.company_name', $search);
                $this->db->group_end();
            }
        }

        if($filters['location_id'] != 'all')
        {
            $this->db->where('sales_items.item_location', $filters['location_id']);
        }

        if($filters['only_invoices'] != FALSE)
        {
            $this->db->where('receivings.invoice_number IS NOT NULL');
        }

        if($filters['only_cash'] != FALSE)
        {
            $this->db->group_start();
                $this->db->like('payments.payment_type', $this->lang->line('sales_cash'));
                $this->db->or_where('payments.payment_type IS NULL');
            $this->db->group_end();
        }

        if($filters['only_due'] != FALSE)
        {
            $this->db->like('payments.payment_type', $this->lang->line('sales_due'));
        }

        if($filters['only_check'] != FALSE)
        {
            $this->db->like('payments.payment_type', $this->lang->line('sales_check'));
        }

        if($filters['only_debit'] != FALSE)
        {
            $this->db->like('payments.payment_type', $this->lang->line('sales_debit'));
        }

        if($filters['only_credit'] != FALSE)
        {
            $this->db->like('payments.payment_type', $this->lang->line('sales_credit'));
        }

        // get_found_rows case
        if($count_only == TRUE)
        {
            return $this->db->get()->row()->count;
        }

        $this->db->group_by('receivings.sale_id');

        // order by sale time by default
        $this->db->order_by($sort, $order);

        if($rows > 0)
        {
            $this->db->limit($rows, $limit_from);
        }

        return $this->db->get();
    }

    /**
     * Get the payment summary for the takings (receivings/manage) view
     */
    public function get_payments_summary($search, $filters)
    {
        // get payment summary
        $this->db->select('payment_type, COUNT(payment_amount) AS count, SUM(payment_amount) AS payment_amount');
        $this->db->from('receivings AS receivings');
        $this->db->join('sales_payments', 'sales_payments.sale_id = receivings.sale_id');
        $this->db->join('people AS customer_p', 'receivings.customer_id = customer_p.person_id', 'LEFT');
        $this->db->join('suppliers AS customer', 'receivings.customer_id = suppliers.person_id', 'LEFT');

        if(empty($this->config->item('date_or_time_format')))
        {
            $this->db->where('DATE(receivings.sale_time) BETWEEN ' . $this->db->escape($filters['start_date']) . ' AND ' . $this->db->escape($filters['end_date']));
        }
        else
        {
            $this->db->where('receivings.sale_time BETWEEN ' . $this->db->escape(rawurldecode($filters['start_date'])) . ' AND ' . $this->db->escape(rawurldecode($filters['end_date'])));
        }

        if(!empty($search))
        {
            if($filters['is_valid_receipt'] != FALSE)
            {
                $pieces = explode(' ',$search);
                $this->db->where('receivings.sale_id', $pieces[1]);
            }
            else
            {
                $this->db->group_start();
                    // customer last name
                    $this->db->like('customer_p.last_name', $search);
                    // customer first name
                    $this->db->or_like('customer_p.first_name', $search);
                    // customer first and last name
                    $this->db->or_like('CONCAT(customer_p.first_name, " ", customer_p.last_name)', $search);
                    // customer company name
                    $this->db->or_like('suppliers.company_name', $search);
                $this->db->group_end();
            }
        }

        if($filters['sale_type'] == 'sales')
        {
            $this->db->where('receivings.sale_status = ' . COMPLETED . ' AND payment_amount > 0');
        }
        elseif($filters['sale_type'] == 'quotes')
        {
            $this->db->where('receivings.sale_status = ' . SUSPENDED . ' AND receivings.quote_number IS NOT NULL');
        }
        elseif($filters['sale_type'] == 'returns')
        {
            $this->db->where('receivings.sale_status = ' . COMPLETED . ' AND payment_amount < 0');
        }
        elseif($filters['sale_type'] == 'all')
        {
            $this->db->where('receivings.sale_status = ' . COMPLETED);
        }

        if($filters['only_invoices'] != FALSE)
        {
            $this->db->where('invoice_number IS NOT NULL');
        }

        if($filters['only_cash'] != FALSE)
        {
            $this->db->like('payment_type', $this->lang->line('sales_cash'));
        }

        if($filters['only_due'] != FALSE)
        {
            $this->db->like('payment_type', $this->lang->line('sales_due'));
        }

        if($filters['only_check'] != FALSE)
        {
            $this->db->like('payment_type', $this->lang->line('sales_check'));
        }

        if($filters['only_debit'] != FALSE)
        {
            $this->db->like('payment_type', $this->lang->line('sales_debit'));
        }

        if($filters['only_credit'] != FALSE)
        {
            $this->db->like('payment_type', $this->lang->line('sales_credit'));
        }

        $this->db->group_by('payment_type');

        $payments = $this->db->get()->result_array();

        // consider Gift Card as only one type of payment and do not show "Gift Card: 1, Gift Card: 2, etc." in the total
        $gift_card_count = 0;
        $gift_card_amount = 0;
        foreach($payments as $key=>$payment)
        {
            if(strstr($payment['payment_type'], $this->lang->line('sales_giftcard')) != FALSE)
            {
                $gift_card_count  += $payment['count'];
                $gift_card_amount += $payment['payment_amount'];

                // remove the "Gift Card: 1", "Gift Card: 2", etc. payment string
                unset($payments[$key]);
            }
        }

        if($gift_card_count > 0)
        {
            $payments[] = array('payment_type' => $this->lang->line('sales_giftcard'), 'count' => $gift_card_count, 'payment_amount' => $gift_card_amount);
        }

        return $payments;
    }
}
?>
