<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use App\Models\invoices_items;
use Illuminate\Support\Facades\DB;
use Carbon\Carbon;

class Invoices extends Model
{
	protected $table = 'invoices';
	protected $fillable = [
		'*',
	];

	public static function generateInvoiceNo($AgencyID)
	{
		$responsedata = Invoices::where('AgencyID', $AgencyID)->orderBy('id', 'DESC')->first();
		$prein = isset($responsedata->invoiceNo) ? $responsedata->invoiceNo : 'INV-0';

		$array = explode('INV-', $prein);
		$number = 0000000;
		$invnumber = $number + $array[1] + 1;
		$nub = sprintf("%07d", $invnumber);
		$inv = 'INV-' . $nub;
		return $inv;
	}
	public static function getinvoicelist($User, $post = array())
	{
		$responsedata = Invoices::select(
			'invoices.*',
			'invoices.id as invoice_id',
			DB::raw('DATE_FORMAT(invoices.created_at, "%d %b, %Y") as createdDate'),
			DB::raw('DATE_FORMAT(invoices.InvoiceDueDate, "%d %b, %Y") as DueDate'),
			'users.name as label',
			'users.name',
			'users.UserType',
			'users.email',
			'users.email as value',
			'users.mobile',
			'users.id',
			'mst_currency.name as symbol',
		)->leftjoin('users', 'users.id', '=', 'invoices.customer_id')
			->leftjoin('mst_currency', 'mst_currency.id', '=', 'invoices.InvoiceCurrency')
			->where(function ($query) use ($User) {
				if ($User->UserType == 1) {
					$query->where('invoices.AgencyID', $User->id);
				} else {
					$query->where('invoices.UserSysId', $User->id);
				}
			})
			->where(function ($query) use ($post) {
				if (!empty($post['UserType']) && $post['UserType'] != 3) {
					$query->where('users.UserType', $post['UserType']);
				}
				if (!empty($post['invoiceNo'])) {
					$query->where('invoices.invoiceNo', 'like', '%' . $post['invoiceNo'] . '%');
				}
				if (!empty($post['SupplierState'])) {
					$query->where('invoices.SupplierState', $post['SupplierState']);
				}
				if (!empty($post['status']) && $post['status'] != 'ALL') {
					$query->where('invoices.status', $post['status']);
				}
				if (!empty($post['FromDate']) && !empty($post['ToDate'])) {
					$query->whereBetween('invoices.InvoiceDate', [$post['FromDate'], $post['ToDate']]);
				}
				if (!empty($post['FromDateCreate']) && !empty($post['ToDateCreate'])) {
					$query->whereBetween('invoices.created_at', [$post['FromDateCreate'], $post['ToDateCreate']]);
				}
			})
			->orderBy('invoices.id', 'DESC')->limit(25)->get();

		$resultNew = [];
		if ($responsedata) {
			foreach ($responsedata->toArray() as $key => $value) {

				$items = invoices_items::where('invoice_id', $value['invoice_id'])->orderBy('id', 'ASC')->get();
				$invoice_id = encrypts($value['invoice_id'], env('SECURITYKEY'), env('SECURITYKEY'));
				$fromDate = Carbon::parse(Carbon::now());
				$toDate = Carbon::parse($value['InvoiceDueDate']);
				$InvoiceDate = Carbon::parse($value['InvoiceDate']);
				$days = $toDate->diffInDays($fromDate->format('Y-m-d'));

				$isGreater = $toDate->gt($fromDate);
				// pr($fromDate.'='.$value['invoiceNo'].'='.$days.'=='.$isGreater);
				$BalanceDue = $value['TotalAmount'] - $value['TotalAmountRec'];
				if ($value['status'] == 1) {
					$invoiceStatus = 'PAID';
					$StatusColor = '#069b5f';
				} elseif ($value['status'] == 2) {
					$invoiceStatus = 'DRAFT';
					$StatusColor = '#929797';
				} elseif ($value['status'] == 3 && $days > 0 && $BalanceDue > 0 && !$isGreater) {
					$invoiceStatus = 'OVERDUE BY ' . $days . ' DAYS';
					$StatusColor = '#f59d00';
				} elseif ($value['status'] == 3 && $isGreater) {
					$invoiceStatus = 'DUE IN ' . $days . ' DAYS';
					$StatusColor = '#1f7bff';
				} else {
					$invoiceStatus = 'TODAY IS DUE';
					$StatusColor = '#ff1f1f';
				}
				$resultNew[$key] = $value;
				$resultNew[$key]['invoice_id'] = $invoice_id;
				$resultNew[$key]['overdueDays'] = $days;
				$resultNew[$key]['BalanceDue'] = ($BalanceDue);
				$resultNew[$key]['invoiceStatus'] = $invoiceStatus;
				$resultNew[$key]['StatusColor'] = $StatusColor;
				$resultNew[$key]['items'] = ($items) ? $items->toArray() : [];
			}
		}
		// echo '<pre>';
		// print_r($resultNew);
		// die;
		return $resultNew;
	}
	public static function invoicelist($User, $post = array(), $perPage)
	{
		// $responsedata = Invoices::select(
		// 	// 'invoices.*',
		// 	'invoices.id as invoice_id',
		// 	'invoices.invoiceNo',
		// 	'invoices.status',
		// 	'invoices.TotalAmount',
		// 	'invoices.TotalAmountRec',
		// 	'invoices.InvoiceDueDate',
		// 	'invoices.InvoiceDate',
		// 	'invoices.TPSystemID',
		// 	DB::raw('DATE_FORMAT(invoices.created_at, "%d %b, %Y") as createdDate'),
		// 	DB::raw('DATE_FORMAT(invoices.InvoiceDueDate, "%d %b, %Y") as DueDate'),
		// 	'users.name as label',
		// 	'users.name',
		// 	'users.UserType',
		// 	'users.email',
		// 	'users.email as value',
		// 	'users.mobile',
		// 	'users.id',
		// 	'mst_currency.name as symbol',
		// )->leftjoin('users', 'users.id', '=', 'invoices.customer_id')
		// 	->leftjoin('mst_currency', 'mst_currency.id', '=', 'invoices.InvoiceCurrency')
		// 	->where(function ($query) use ($User) {
		// 		if ($User->UserType == 1) {
		// 			$query->where('invoices.AgencyID', $User->id);
		// 		} else {
		// 			$query->where('invoices.UserSysId', $User->id);
		// 		}
		// 	})
		// 	->where(function ($query) use ($post) {
		// 		if (!empty($post['UserType']) && $post['UserType'] != 3) {
		// 			$query->where('users.UserType', $post['UserType']);
		// 		}
		// 		if (!empty($post['invoiceNo'])) {
		// 			$query->where('invoices.invoiceNo', 'like', '%' . $post['invoiceNo'] . '%');
		// 		}
		// 		if (!empty($post['SupplierState'])) {
		// 			$query->where('invoices.SupplierState', $post['SupplierState']);
		// 		}
		// 		if (!empty($post['status']) && $post['status'] != 'ALL') {
		// 			$query->where('invoices.status', $post['status']);
		// 		}
		// 		if (!empty($post['FromDate']) && !empty($post['ToDate'])) {
		// 			$query->whereBetween('invoices.InvoiceDate', [$post['FromDate'], $post['ToDate']]);
		// 		}
		// 		if (!empty($post['FromDateCreate']) && !empty($post['ToDateCreate'])) {
		// 			$query->whereBetween('invoices.created_at', [$post['FromDateCreate'], $post['ToDateCreate']]);
		// 		}
		// 	})
		// 	->orderBy('invoices.id', 'DESC')->limit(25)->get();

		// $resultNew = [];
		// if ($responsedata) {
		// 	foreach ($responsedata->toArray() as $key => $value) {
		// 		$invoice_id = encrypts($value['invoice_id'], env('SECURITYKEY'), env('SECURITYKEY'));
		// 		$fromDate = Carbon::parse(Carbon::now());
		// 		$toDate = Carbon::parse($value['InvoiceDueDate']);
		// 		$InvoiceDate = Carbon::parse($value['InvoiceDate']);
		// 		$days = $toDate->diffInDays($fromDate->format('Y-m-d'));

		// 		$isGreater = $toDate->gt($fromDate);
		// 		$BalanceDue = $value['TotalAmount'] - $value['TotalAmountRec'];
		// 		if ($value['status'] == 1) {
		// 			$invoiceStatus = 'PAID';
		// 			$StatusColor = '#069b5f';
		// 		} elseif ($value['status'] == 2) {
		// 			$invoiceStatus = 'DRAFT';
		// 			$StatusColor = '#929797';
		// 		} elseif ($value['status'] == 3 && $days > 0 && $BalanceDue > 0 && !$isGreater) {
		// 			$invoiceStatus = 'OVERDUE BY ' . $days . ' DAYS';
		// 			$StatusColor = '#f59d00';
		// 		} elseif ($value['status'] == 3 && $isGreater) {
		// 			$invoiceStatus = 'DUE IN ' . $days . ' DAYS';
		// 			$StatusColor = '#1f7bff';
		// 		} else {
		// 			$invoiceStatus = 'TODAY IS DUE';
		// 			$StatusColor = '#ff1f1f';
		// 		}
		// 		$resultNew[$key] = $value;
		// 		$resultNew[$key]['invoice_id'] = $invoice_id;
		// 		$resultNew[$key]['overdueDays'] = $days;
		// 		$resultNew[$key]['BalanceDue'] = ($BalanceDue);
		// 		$resultNew[$key]['invoiceStatus'] = $invoiceStatus;
		// 		$resultNew[$key]['StatusColor'] = $StatusColor;
		// 	}
		// }
		// return $resultNew;


		$today = Carbon::now()->format('Y-m-d');

		$responsedata = Invoices::select(
			'invoices.id as invoice_id',
			'invoices.invoiceNo',
			'invoices.status',
			'invoices.TotalAmount',
			'invoices.TotalAmountRec',
			'invoices.InvoiceDueDate',
			'invoices.InvoiceDate',
			'invoices.TPSystemID',
			'invoices.created_at',
			DB::raw('DATE_FORMAT(invoices.created_at, "%d %b, %Y") as createdDate'),
			DB::raw('DATE_FORMAT(invoices.InvoiceDueDate, "%d %b, %Y") as DueDate'),
			'users.name as label',
			'users.name',
			'users.UserType',
			'users.email',
			'users.email as value',
			'users.mobile',
			'users.id',
			'mst_currency.name as symbol',

			// ✅ Calculated columns
			DB::raw('(invoices.TotalAmount - invoices.TotalAmountRec) AS BalanceDue'),
			DB::raw("DATEDIFF(invoices.InvoiceDueDate, '$today') AS overdueDays"),

			// ✅ Dynamic status text logic
			DB::raw("
        CASE 
            WHEN invoices.status = 1 THEN 'PAID'
            WHEN invoices.status = 2 THEN 'DRAFT'
            WHEN invoices.status = 3 AND (invoices.InvoiceDueDate < '$today') AND (invoices.TotalAmount - invoices.TotalAmountRec) > 0 THEN 
                CONCAT('OVERDUE BY ', ABS(DATEDIFF(invoices.InvoiceDueDate, '$today')), ' DAYS')
            WHEN invoices.status = 3 AND (invoices.InvoiceDueDate > '$today') THEN 
                CONCAT('DUE IN ', DATEDIFF(invoices.InvoiceDueDate, '$today'), ' DAYS')
            ELSE 'TODAY IS DUE'
        END AS invoiceStatus
    "),

			// ✅ Dynamic color logic
			DB::raw("
        CASE 
            WHEN invoices.status = 1 THEN '#069b5f'
            WHEN invoices.status = 2 THEN '#929797'
            WHEN invoices.status = 3 AND (invoices.InvoiceDueDate < '$today') AND (invoices.TotalAmount - invoices.TotalAmountRec) > 0 THEN '#f59d00'
            WHEN invoices.status = 3 AND (invoices.InvoiceDueDate > '$today') THEN '#1f7bff'
            ELSE '#ff1f1f'
        END AS StatusColor
    ")
		)
			->leftJoin('users', 'users.id', '=', 'invoices.customer_id')
			->leftJoin('mst_currency', 'mst_currency.id', '=', 'invoices.InvoiceCurrency')
			->when($User->UserType == 1, fn($q) => $q->where('invoices.AgencyID', $User->id))
			->when($User->UserType != 1, fn($q) => $q->where('invoices.UserSysId', $User->id))
			->when(!empty($post['UserType']) && $post['UserType'] != 3, fn($q) => $q->where('users.UserType', $post['UserType']))
			->when(!empty($post['invoiceNo']), fn($q) => $q->where('invoices.invoiceNo', 'like', '%' . $post['invoiceNo'] . '%'))
			->when(!empty($post['SupplierState']), fn($q) => $q->where('invoices.SupplierState', $post['SupplierState']))
			->when(!empty($post['status']) && $post['status'] != 'ALL', fn($q) => $q->where('invoices.status', $post['status']))
			->when(!empty($post['FromDate']) && !empty($post['ToDate']), fn($q) => $q->whereBetween('invoices.InvoiceDate', [$post['FromDate'], $post['ToDate']]))
			->when(!empty($post['FromDateCreate']) && !empty($post['ToDateCreate']), fn($q) => $q->whereBetween('invoices.created_at', [$post['FromDateCreate'], $post['ToDateCreate']]))
			->orderBy('invoices.id', 'DESC')
			->paginate($perPage);
		// $resultNew = $responsedata->getCollection()->map(function ($value) {
		// 	$value->invoice_id = encrypts($value->invoice_id, env('SECURITYKEY'), env('SECURITYKEY'));
		// 	return $value;
		// });

		// $responsedata->setCollection($resultNew);

		return $responsedata;
	}
	public static function getinvoiceDetails($User, $param)
	{
		$invoice_id = isset($param['invoice_id']) ? $param['invoice_id'] : 0;
		if ($invoice_id > 0) {
			$responsedata = Invoices::select(
				'invoices.*',
				'invoices.id as invoice_id',
				'invoices.SupplierState as mst_state_id',
				DB::raw('DATE_FORMAT(invoices.created_at, "%d %b, %Y") as createdDate'),
				DB::raw('DATE_FORMAT(invoices.InvoiceDueDate, "%d %b, %Y") as DueDate'),
				'users.name as label',
				'users.name',
				'users.fname',
				'users.lname',
				'users.UserType',
				'users.email',
				'users.email as value',
				'users.mobile',
				'users.id',
				'mst_currency.name as symbol',
				'incorporation_details.address',
				'incorporation_details.address1',
				'incorporation_details.country',
				'incorporation_details.city',
				'incorporation_details.pincode',
				'incorporation_details.tax_number as GSTIN',
				'mst_state.name as SupplierStateName',
				'mst_tds_taxes.sections as tdssections',
				'mst_tds_taxes.tax_name',
			)->where('invoices.id', $invoice_id)
				->leftjoin('users', 'users.id', '=', 'invoices.customer_id')
				->leftjoin('incorporation_details', 'incorporation_details.UserSysId', '=', 'users.id')
				->leftjoin('mst_state', 'mst_state.id', '=', 'invoices.SupplierState')
				->leftjoin('mst_currency', 'mst_currency.id', '=', 'invoices.InvoiceCurrency')
				->leftjoin('mst_tds_taxes', 'mst_tds_taxes.id', '=', 'invoices.tdstaxid')
				->where(function ($query) use ($User) {
					if ($User->UserType == 1) {
						$query->where('invoices.AgencyID', $User->id);
					} else {
						$query->where('invoices.UserSysId', $User->id);
					}
				})->first();
			if ($responsedata) {
				$responsedata = $responsedata->toArray();
				// pr($responsedata);die;
				$items = invoices_items::select(
					'invoices_items.*',
					'invoices_items.id as invoices_items_id',
					'mst_items.name as Item',
					'mst_items.types',
					'mst_items.HSNCode',
					'mst_items.Description',
					'mst_items.SellingPrice',
					'mst_items.TaxPreference',
				)->where('invoices_items.invoice_id', $responsedata['invoice_id'])->leftjoin('mst_items', 'mst_items.id', '=', 'invoices_items.Itemid')->orderBy('invoices_items.id', 'ASC')->get();
				///$items = invoices_items::where('invoice_id', $responsedata['invoice_id'])->orderBy('id', 'ASC')->get();
				$ItemsNew = [];
				if ($items) {
					foreach ($items->toArray() as $key => $itemvalue) {
						$itemData = [
							"label" => isset($itemvalue['ItemName']) ? $itemvalue['ItemName'] : '',
							"value" => isset($itemvalue['ItemName']) ? $itemvalue['ItemName'] : '',
							"id" => isset($itemvalue['Itemid']) ? $itemvalue['Itemid'] : '',
							"SellingPrice" => isset($itemvalue['SellingPrice']) ? $itemvalue['SellingPrice'] : '',
							"types" => isset($itemvalue['types']) ? $itemvalue['types'] : 1,
							"TaxPreference" => isset($itemvalue['TaxPreference']) ? $itemvalue['TaxPreference'] : 1,
							"Description" => isset($itemvalue['Description']) ? $itemvalue['Description'] : '',
							"HSNCode" => isset($itemvalue['HSNCode']) ? $itemvalue['HSNCode'] : ''
						];
						$ItemsNew[$key] = $itemvalue;
						$ItemsNew[$key]['Itemid'] = $itemData;
					}
				}
				$invoice_id = encrypts($responsedata['invoice_id'], env('SECURITYKEY'), env('SECURITYKEY'));
				$fromDate = Carbon::parse(Carbon::now());
				$toDate = Carbon::parse($responsedata['InvoiceDueDate']);
				$days = $toDate->diffInDays($fromDate);
				$InvoiceDate = Carbon::parse($responsedata['InvoiceDate']);
				$isGreater = $toDate->gt($fromDate);
				// var_dump($isGreater);
				$BalanceDue = $responsedata['TotalAmount'] - $responsedata['TotalAmountRec'];
				if ($responsedata['status'] == 1) {
					$invoiceStatus = 'PAID';
					$StatusColor = '#069b5f';
					$StatusClass = 'ribbon-success';
				} elseif ($responsedata['status'] == 2) {
					$invoiceStatus = 'DRAFT';
					$StatusColor = '#929797';
					$StatusClass = 'ribbon-draft';
				} elseif ($responsedata['status'] == 3 && $days > 0 && $BalanceDue > 0 && !$isGreater) {
					$invoiceStatus = 'OVERDUE BY ' . $days . ' DAYS';
					$StatusColor = '#f59d00';
					$StatusClass = 'ribbon-overdue';
				} elseif ($responsedata['status'] == 3 && $isGreater) {
					$invoiceStatus = 'DUE IN ' . $days . ' DAYS';
					$StatusColor = '#1f7bff';
					$StatusClass = 'ribbon-duein';
				} else {
					$invoiceStatus = 'TODAY IS DUE';
					$StatusColor = '#ff1f1f';
					$StatusClass = 'ribbon-todaydue';
				}

				$responsedata['invoice_id'] = $invoice_id;
				$responsedata['overdueDays'] = $days;
				$responsedata['BalanceDue'] = ($responsedata['TotalAmount'] - $responsedata['TotalAmountRec']);
				$responsedata['TotalAmountStr'] = toCurrency($responsedata['TotalAmount'], $responsedata['symbol']);
				$responsedata['invoiceStatus'] = $invoiceStatus;
				$responsedata['StatusColor'] = $StatusColor;
				$responsedata['StatusClass'] = $StatusClass;
				$responsedata['TaxTypeobj'] = json_decode($responsedata['TaxTypeobj'], 1);
				$responsedata['AmountInWord'] = self::convert_number_to_words($responsedata['TotalAmount']);
				$responsedata['items'] = ($ItemsNew) ? $ItemsNew : [];
			}
			return $responsedata;
		} else {
			return null;
		}

		// echo '<pre>';
		// print_r($resultNew);
		// die;
	}

	public static function convert_number_to_words(float $number)
	{


		$decimal = round($number - ($no = floor($number)), 2) * 100;
		$hundred = null;
		$digits_length = strlen($no);
		$i = 0;
		$str = array();
		$words = array(
			0  => 'zero',
			1  => 'one',
			2  => 'two',
			3  => 'three',
			4  => 'four',
			5  => 'five',
			6  => 'six',
			7  => 'seven',
			8  => 'eight',
			9  => 'nine',
			10 => 'ten',
			11 => 'eleven',
			12 => 'twelve',
			13 => 'thirteen',
			14 => 'fourteen',
			15 => 'fifteen',
			16 => 'sixteen',
			17 => 'seventeen',
			18 => 'eighteen',
			19 => 'nineteen',
			20 => 'twenty',
			21 => 'twenty one',
			22 => 'twenty two',
			23 => 'twenty three',
			24 => 'twenty four',
			25 => 'twenty five',
			26 => 'twenty six',
			27 => 'twenty seven',
			28 => 'twenty eight',
			29 => 'twenty nine',
			30 => 'thirty',
			31 => 'thirty one',
			32 => 'thirty two',
			33 => 'thirty three',
			34 => 'thirty four',
			35 => 'thirty five',
			36 => 'thirty six',
			37 => 'thirty seven',
			38 => 'thirty eight',
			39 => 'thirty nine',
			40 => 'forty',
			41 => 'forty one',
			42 => 'forty two',
			43 => 'forty three',
			44 => 'forty four',
			45 => 'forty five',
			46 => 'forty six',
			47 => 'forty seven',
			48 => 'forty eight',
			49 => 'forty nine',
			50 => 'fifty',
			51 => 'fifty one',
			52 => 'fifty two',
			53 => 'fifty three',
			54 => 'fifty four',
			55 => 'fifty five',
			56 => 'fifty six',
			57 => 'fifty seven',
			58 => 'fifty eight',
			59 => 'fifty nine',
			60 => 'sixty',
			61 => 'sixty one',
			62 => 'sixty two',
			63 => 'sixty three',
			64 => 'sixty four',
			65 => 'sixty five',
			66 => 'sixty six',
			67 => 'sixty seven',
			68 => 'sixty eight',
			69 => 'sixty nine',
			70 => 'seventy',
			71 => 'seventy one',
			72 => 'seventy two',
			73 => 'seventy three',
			74 => 'seventy four',
			75 => 'seventy five',
			76 => 'seventy six',
			77 => 'seventy seven',
			78 => 'seventy eight',
			79 => 'seventy nine',
			80 => 'eighty',
			81 => 'eighty one',
			82 => 'eighty two',
			83 => 'eighty three',
			84 => 'eighty four',
			85 => 'eighty five',
			86 => 'eighty six',
			87 => 'eighty seven',
			88 => 'eighty eight',
			89 => 'eighty nine',
			90 => 'ninety',
			91 => 'ninety one',
			92 => 'ninety two',
			93 => 'ninety three',
			94 => 'ninety four',
			95 => 'ninety five',
			96 => 'ninety six',
			97 => 'ninety seven',
			98 => 'ninety eight',
			99 => 'ninety nine'
		);

		$digits = array('', 'hundred', 'thousand', 'lakh', 'crore');
		while ($i < $digits_length) {
			$divider = ($i == 2) ? 10 : 100;
			$number = floor($no % $divider);
			$no = floor($no / $divider);
			$i += $divider == 10 ? 1 : 2;
			if ($number) {
				$plural = (($counter = count($str)) && $number > 9) ? 's' : null;
				$hundred = ($counter == 1 && $str[0]) ? ' and ' : null;
				$str[] = ($number < 21) ? $words[$number] . ' ' . $digits[$counter] . $plural . ' ' . $hundred : $words[floor($number / 10) * 10] . ' ' . $words[$number % 10] . ' ' . $digits[$counter] . $plural . ' ' . $hundred;
			} else $str[] = null;
		}
		$Rupees = implode('', array_reverse($str));

		$paise = ($decimal > 0) ? "and " . ($words[$decimal] . " " . $words[$decimal % 10]) . ' Paise' : '';
		return ucwords($Rupees ? $Rupees . 'Rupees ' : '') . $paise;
	}

	public static function TotalCurrent($User)
	{
		$fromDate = Carbon::parse(Carbon::now());
		$TotalCurrent = Invoices::select(DB::raw("(sum(TotalAmount)) as TotalAmounts"))
			->where(function ($query) use ($User) {
				if ($User->UserType == 1) {
					$query->where('invoices.AgencyID', $User->id);
				} else {
					$query->where('invoices.UserSysId', $User->id);
				}
			})->where('status', 3)
			->where('InvoiceDueDate', '>', $fromDate->format('Y-m-d'))->orderBy('created_at')
			->groupBy('status')
			->first();
		return ($TotalCurrent) ? $TotalCurrent->toArray() : [];
	}
	public static function TotalOverdue($User)
	{
		$fromDate = Carbon::parse(Carbon::now());
		$TotalCurrent = Invoices::select(DB::raw("(sum(TotalAmount)) as TotalAmounts"))
			->where(function ($query) use ($User) {
				if ($User->UserType == 1) {
					$query->where('invoices.AgencyID', $User->id);
				} else {
					$query->where('invoices.UserSysId', $User->id);
				}
			})->where('status', 3)
			->whereRaw('DATEDIFF(CURDATE(), InvoiceDueDate) > ?', [0])
			->where('InvoiceDueDate', '<', $fromDate->format('Y-m-d'))->orderBy('created_at')
			->groupBy('status')
			->first();
		return ($TotalCurrent) ? $TotalCurrent->toArray() : [];
	}
	public static function TotalDraft($User)
	{
		$fromDate = Carbon::parse(Carbon::now());
		$TotalCurrent = Invoices::select(DB::raw("(sum(TotalAmount)) as TotalAmounts"))
			->where(function ($query) use ($User) {
				if ($User->UserType == 1) {
					$query->where('invoices.AgencyID', $User->id);
				} else {
					$query->where('invoices.UserSysId', $User->id);
				}
			})->where('status', 2)
			->groupBy('status')
			->first();
		return ($TotalCurrent) ? $TotalCurrent->toArray() : [];
	}
	public static function TotalReceived($User)
	{
		$fromDate = Carbon::parse(Carbon::now());
		$TotalCurrent = Invoices::select(DB::raw("(sum(TotalAmount)) as TotalAmounts"))
			->where(function ($query) use ($User) {
				if ($User->UserType == 1) {
					$query->where('invoices.AgencyID', $User->id);
				} else {
					$query->where('invoices.UserSysId', $User->id);
				}
			})->where('status', 1)
			->groupBy('status')
			->first();
		return ($TotalCurrent) ? $TotalCurrent->toArray() : [];
	}
	public static function SalesStatistics($User)
	{
		$TotalMonthWise = Invoices::select(
			"id",
			'PlanType',
			DB::raw("(sum(TotalAmount)) as TotalAmounts"),
			DB::raw("(DATE_FORMAT(created_at, '%Y-%m-%d')) as month")
		)->where(function ($query) use ($User) {
			if ($User->UserType == 1) {
				$query->where('invoices.AgencyID', $User->id);
			} else {
				$query->where('invoices.UserSysId', $User->id);
			}
		})->orderBy('created_at')->groupBy('PlanType')
			->groupBy(DB::raw("DATE_FORMAT(created_at, '%Y-%m')"))
			->get();

		$DataSetNew = [];
		if ($TotalMonthWise) {
			$TotalMonthWise = $TotalMonthWise->toArray();
			foreach ($TotalMonthWise as $key => $value) {
				$month = date('M', strtotime($value['month']));
				$DataSetNew[$month][$value['PlanType']] = $value;
			}
		}
		$DataSet = json_decode('[{"month":"Jan"},{"month":"Feb"},{"month":"Mar"},{"month":"Apr"},{"month":"May"},{"month":"Jun"},{"month":"Jul"},{"month":"Aug"},{"month":"Sep"},{"month":"Oct"},{"month":"Nov"},{"month":"Dec"}]', 1);
		$DataSetFinal = [];
		foreach ($DataSet as $key => $value) {
			$months = $value['month'];
			if (isset($DataSetNew[$months])) {
				$val = $DataSetNew[$months];
				$DataSetFinal[$key] = [
					"Flight" => isset($val[1]) ? round($val[1]['TotalAmounts'], 2) : 0,
					"Hotel" => isset($val[2]) ? round($val[2]['TotalAmounts'], 2) : 0,
					"Bus" => isset($val[3]) ? round($val[3]['TotalAmounts'], 2) : 0,
					"Miscellaneous" => isset($val[4]) ? round($val[4]['TotalAmounts'], 2) : 0,
					"month" => $months
				];
			} else {
				$DataSetFinal[$key] = [
					"Flight" => 0,
					"Hotel" => 0,
					"Bus" => 0,
					"Miscellaneous" => 0,
					"month" => $months
				];
			}
		}
		return !empty($DataSetFinal) ? $DataSetFinal : [];
	}

	public static function TotalInvoice($User)
	{
		$TotalCurrent = Invoices::select(DB::raw("(sum(TotalAmount)) as TotalAmounts"))
			->where(function ($query) use ($User) {
				if ($User->UserType == 1) {
					$query->where('invoices.AgencyID', $User->id);
				} else {
					$query->where('invoices.UserSysId', $User->id);
				}
			})->first();
		return ($TotalCurrent) ? $TotalCurrent->toArray() : [];
	}

	public static function invoiceData($AgencyID, $param)
	{
		$invoice_id = isset($param['invoice_id']) ? $param['invoice_id'] : 0;
		if ($invoice_id > 0) {
			$responsedata = Invoices::select(
				'invoices.*',
				'invoices.id as invoice_id',
				'invoices.SupplierState as mst_state_id',
				DB::raw('DATE_FORMAT(invoices.created_at, "%d %b, %Y") as createdDate'),
				'invoices.updated_at as lastupdated_at',
				DB::raw('DATE_FORMAT(invoices.InvoiceDueDate, "%d %b, %Y") as DueDate'),
				'users.name as label',
				'users.name',
				'users.fname',
				'users.lname',
				'users.UserType',
				'users.email',
				'users.email as value',
				'users.mobile',
				'users.id',
				'mst_currency.name as symbol',
				'incorporation_details.address',
				'incorporation_details.address1',
				'incorporation_details.country',
				'incorporation_details.city',
				'incorporation_details.pincode',
				'incorporation_details.tax_number as GSTIN',
				'mst_state.name as SupplierStateName',
				'mst_tds_taxes.sections as tdssections',
				'mst_tds_taxes.tax_name',
			)->where('invoices.id', $invoice_id)
				->leftjoin('users', 'users.id', '=', 'invoices.customer_id')
				->leftjoin('incorporation_details', 'incorporation_details.UserSysId', '=', 'users.id')
				->leftjoin('mst_state', 'mst_state.id', '=', 'invoices.SupplierState')
				->leftjoin('mst_currency', 'mst_currency.id', '=', 'invoices.InvoiceCurrency')
				->leftjoin('mst_tds_taxes', 'mst_tds_taxes.id', '=', 'invoices.tdstaxid')
				->where(function ($query) use ($AgencyID) {
					if ($AgencyID) {
						$query->where('invoices.AgencyID', $AgencyID);
					}
				})->first();
			if ($responsedata) {
				$responsedata = $responsedata->toArray();
				// pr($responsedata);die;
				$items = invoices_items::select(
					'invoices_items.*',
					'invoices_items.id as invoices_items_id',
					'mst_items.name as Item',
					'mst_items.types',
					'mst_items.HSNCode',
					'mst_items.Description',
					'mst_items.SellingPrice',
					'mst_items.TaxPreference',
				)->where('invoices_items.invoice_id', $responsedata['invoice_id'])->leftjoin('mst_items', 'mst_items.id', '=', 'invoices_items.Itemid')->orderBy('invoices_items.id', 'ASC')->get();
				///$items = invoices_items::where('invoice_id', $responsedata['invoice_id'])->orderBy('id', 'ASC')->get();
				$ItemsNew = [];
				if ($items) {
					foreach ($items->toArray() as $key => $itemvalue) {
						$itemData = [
							"label" => isset($itemvalue['ItemName']) ? $itemvalue['ItemName'] : '',
							"value" => isset($itemvalue['ItemName']) ? $itemvalue['ItemName'] : '',
							"id" => isset($itemvalue['Itemid']) ? $itemvalue['Itemid'] : '',
							"SellingPrice" => isset($itemvalue['SellingPrice']) ? $itemvalue['SellingPrice'] : '',
							"types" => isset($itemvalue['types']) ? $itemvalue['types'] : 1,
							"TaxPreference" => isset($itemvalue['TaxPreference']) ? $itemvalue['TaxPreference'] : 1,
							"Description" => isset($itemvalue['Description']) ? $itemvalue['Description'] : '',
							"HSNCode" => isset($itemvalue['HSNCode']) ? $itemvalue['HSNCode'] : ''
						];
						$ItemsNew[$key] = $itemvalue;
						$ItemsNew[$key]['Itemid'] = $itemData;
					}
				}
				$invoice_id = encrypts($responsedata['invoice_id'], env('SECURITYKEY'), env('SECURITYKEY'));
				$fromDate = Carbon::parse(Carbon::now());
				$toDate = Carbon::parse($responsedata['InvoiceDueDate']);
				$days = $toDate->diffInDays($fromDate);
				$InvoiceDate = Carbon::parse($responsedata['InvoiceDate']);
				$isGreater = $toDate->gt($fromDate);
				// var_dump($isGreater);
				$BalanceDue = $responsedata['TotalAmount'] - $responsedata['TotalAmountRec'];
				if ($responsedata['status'] == 1) {
					$invoiceStatus = 'PAID';
					$StatusColor = '#069b5f';
					$StatusClass = 'ribbon-success';
				} elseif ($responsedata['status'] == 2) {
					$invoiceStatus = 'DRAFT';
					$StatusColor = '#929797';
					$StatusClass = 'ribbon-draft';
				} elseif ($responsedata['status'] == 3 && $days > 0 && $BalanceDue > 0 && !$isGreater) {
					$invoiceStatus = 'OVERDUE BY ' . $days . ' DAYS';
					$StatusColor = '#f59d00';
					$StatusClass = 'ribbon-overdue';
				} elseif ($responsedata['status'] == 3 && $isGreater) {
					$invoiceStatus = 'DUE IN ' . $days . ' DAYS';
					$StatusColor = '#1f7bff';
					$StatusClass = 'ribbon-duein';
				} else {
					$invoiceStatus = 'TODAY IS DUE';
					$StatusColor = '#ff1f1f';
					$StatusClass = 'ribbon-todaydue';
				}

				$responsedata['invoice_id'] = $invoice_id;
				$responsedata['overdueDays'] = $days;
				$responsedata['BalanceDue'] = ($responsedata['TotalAmount'] - $responsedata['TotalAmountRec']);
				$responsedata['TotalAmountStr'] = toCurrency($responsedata['TotalAmount'], $responsedata['symbol']);
				$responsedata['invoiceStatus'] = $invoiceStatus;
				$responsedata['StatusColor'] = $StatusColor;
				$responsedata['StatusClass'] = $StatusClass;
				$responsedata['TaxTypeobj'] = json_decode($responsedata['TaxTypeobj'], 1);
				$responsedata['AmountInWord'] = self::convert_number_to_words($responsedata['TotalAmount']);
				$responsedata['items'] = ($ItemsNew) ? $ItemsNew : [];
			}
			return $responsedata;
		} else {
			return null;
		}

		// echo '<pre>';
		// print_r($resultNew);
		// die;
	}
}
