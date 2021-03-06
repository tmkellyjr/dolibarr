<?php
/* Copyright (C) 2015   Jean-François Ferry     <jfefe@aternatik.fr>
 *
 * This program is free software; you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation; either version 3 of the License, or
 * (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with this program. If not, see <http://www.gnu.org/licenses/>.
 */

 use Luracast\Restler\RestException;


/**
 * API class for thirdparties
 *
 * @access protected
 * @class  DolibarrApiAccess {@requires user,external}
 *
 */
class Thirdparties extends DolibarrApi
{
    /**
     *
     * @var array   $FIELDS     Mandatory fields, checked when create and update object
     */
    static $FIELDS = array(
        'name'
    );

    /**
     * @var Societe $company {@type Societe}
     */
    public $company;

    /**
     * Constructor
     */
    function __construct()
    {
		global $db, $conf;
		$this->db = $db;

		require_once DOL_DOCUMENT_ROOT.'/societe/class/societe.class.php';
		require_once DOL_DOCUMENT_ROOT.'/categories/class/categorie.class.php';

        $this->company = new Societe($this->db);

        if (! empty($conf->global->SOCIETE_EMAIL_MANDATORY)) {
            static::$FIELDS[] = 'email';
        }
    }

  /**
   * Get properties of a thirdparty object
   *
   * Return an array with thirdparty informations
   *
   * @param 	int 	$id ID of thirdparty
   * @return 	array|mixed data without useless information
	 *
   * @throws 	RestException
   */
    function get($id)
    {
      if(! DolibarrApiAccess::$user->rights->societe->lire) {
        throw new RestException(401);
      }

      $result = $this->company->fetch($id);
      if( ! $result ) {
          throw new RestException(404, 'Thirdparty not found');
      }

      if( ! DolibarrApi::_checkAccessToResource('societe',$this->company->id)) {
        throw new RestException(401, 'Access not allowed for login '.DolibarrApiAccess::$user->login);
      }

      $filterabsolutediscount = "fk_facture_source IS NULL OR (fk_facture_source IS NOT NULL AND (description LIKE '(DEPOSIT)%' AND description NOT LIKE '(EXCESS RECEIVED)%'))";
      $filtercreditnote = "fk_facture_source IS NOT NULL AND (description NOT LIKE '(DEPOSIT)%' OR description LIKE '(EXCESS RECEIVED)%')";
      $absolute_discount = $this->company->getAvailableDiscounts('', $filterabsolutediscount);
      $absolute_creditnote = $this->company->getAvailableDiscounts('', $filtercreditnote);
      $this->company->absolute_discount = price2num($absolute_discount, 'MT');
      $this->company->absolute_creditnote = price2num($absolute_creditnote, 'MT');

		  return $this->_cleanObjectDatas($this->company);
    }

    /**
     * List thirdparties
     *
     * Get a list of thirdparties
     *
     * @param   string  $sortfield  Sort field
     * @param   string  $sortorder  Sort order
     * @param   int     $limit      Limit for list
     * @param   int     $page       Page number
     * @param   int     $mode       Set to 1 to show only customers
     *                              Set to 2 to show only prospects
     *                              Set to 3 to show only those are not customer neither prospect
     * @param   string  $sqlfilters Other criteria to filter answers separated by a comma. Syntax example "(t.nom:like:'TheCompany%') and (t.date_creation:<:'20160101')"
     * @return  array               Array of thirdparty objects
     */
    function index($sortfield = "t.rowid", $sortorder = 'ASC', $limit = 100, $page = 0, $mode=0, $sqlfilters = '') {
        global $db, $conf;

        $obj_ret = array();

        // case of external user, we force socids
        $socids = DolibarrApiAccess::$user->societe_id ? DolibarrApiAccess::$user->societe_id : '';

        // If the internal user must only see his customers, force searching by him
        $search_sale = 0;
        if (! DolibarrApiAccess::$user->rights->societe->client->voir && !$socids) $search_sale = DolibarrApiAccess::$user->id;

        $sql = "SELECT t.rowid";
        if ((!DolibarrApiAccess::$user->rights->societe->client->voir && !$socids) || $search_sale > 0) $sql .= ", sc.fk_soc, sc.fk_user"; // We need these fields in order to filter by sale (including the case where the user can only see his prospects)
        $sql.= " FROM ".MAIN_DB_PREFIX."societe as t";

        if ((!DolibarrApiAccess::$user->rights->societe->client->voir && !$socids) || $search_sale > 0) $sql.= ", ".MAIN_DB_PREFIX."societe_commerciaux as sc"; // We need this table joined to the select in order to filter by sale
        $sql.= ", ".MAIN_DB_PREFIX."c_stcomm as st";
        $sql.= " WHERE t.fk_stcomm = st.id";
        if ($mode == 1) $sql.= " AND t.client IN (1, 3)";
        if ($mode == 2) $sql.= " AND t.client IN (2, 3)";
        if ($mode == 3) $sql.= " AND t.client IN (0)";
        $sql.= ' AND t.entity IN ('.getEntity('societe').')';
        if ((!DolibarrApiAccess::$user->rights->societe->client->voir && !$socids) || $search_sale > 0) $sql.= " AND t.rowid = sc.fk_soc";
        //if ($email != NULL) $sql.= " AND s.email = \"".$email."\"";
        if ($socid) $sql.= " AND t.rowid IN (".$socids.")";
        if ($search_sale > 0) $sql.= " AND t.rowid = sc.fk_soc";		// Join for the needed table to filter by sale
        // Insert sale filter
        if ($search_sale > 0)
        {
            $sql .= " AND sc.fk_user = ".$search_sale;
        }
        // Add sql filters
        if ($sqlfilters)
        {
            if (! DolibarrApi::_checkFilters($sqlfilters))
            {
                throw new RestException(503, 'Error when validating parameter sqlfilters '.$sqlfilters);
            }
	        $regexstring='\(([^:\'\(\)]+:[^:\'\(\)]+:[^:\(\)]+)\)';
            $sql.=" AND (".preg_replace_callback('/'.$regexstring.'/', 'DolibarrApi::_forge_criteria_callback', $sqlfilters).")";
        }

        $sql.= $db->order($sortfield, $sortorder);

        if ($limit) {
            if ($page < 0)
            {
                $page = 0;
            }
            $offset = $limit * $page;

            $sql.= $db->plimit($limit + 1, $offset);
        }

		$result = $db->query($sql);
        if ($result)
        {
            $num = $db->num_rows($result);
            $min = min($num, ($limit <= 0 ? $num : $limit));
            while ($i < $min)
            {
                $obj = $db->fetch_object($result);
                $soc_static = new Societe($db);
                if($soc_static->fetch($obj->rowid)) {
                    $obj_ret[] = $this->_cleanObjectDatas($soc_static);
                }
                $i++;
            }
        }
        else {
            throw new RestException(503, 'Error when retrieve thirdparties : '.$db->lasterror());
        }
        if( ! count($obj_ret)) {
            throw new RestException(404, 'Thirdparties not found');
        }
		return $obj_ret;
    }

    /**
     * Create thirdparty object
     *
     * @param array $request_data   Request datas
     * @return int  ID of thirdparty
     */
    function post($request_data = NULL)
    {
      if(! DolibarrApiAccess::$user->rights->societe->creer) {
        throw new RestException(401);
      }
      // Check mandatory fields
      $result = $this->_validate($request_data);

      foreach($request_data as $field => $value) {
          $this->company->$field = $value;
      }
      if ($this->company->create(DolibarrApiAccess::$user) < 0)
          throw new RestException(500, 'Error creating thirdparty', array_merge(array($this->company->error), $this->company->errors));

      return $this->company->id;
    }

    /**
     * Update thirdparty
     *
     * @param int   $id             Id of thirdparty to update
     * @param array $request_data   Datas
     * @return int
     */
    function put($id, $request_data = NULL)
    {
        if(! DolibarrApiAccess::$user->rights->societe->creer) {
			throw new RestException(401);
		}

        $result = $this->company->fetch($id);
        if( ! $result ) {
            throw new RestException(404, 'Thirdparty not found');
        }

		if( ! DolibarrApi::_checkAccessToResource('societe',$this->company->id)) {
			throw new RestException(401, 'Access not allowed for login '.DolibarrApiAccess::$user->login);
		}

        foreach($request_data as $field => $value) {
            if ($field == 'id') continue;
            $this->company->$field = $value;
        }

        if($this->company->update($id, DolibarrApiAccess::$user,1,'','','update'))
            return $this->get ($id);

        return false;
    }

    /**
     * Delete thirdparty
     *
     * @param int $id   Thirparty ID
     * @return integer
     */
    function delete($id)
    {
      if(! DolibarrApiAccess::$user->rights->societe->supprimer) {
        throw new RestException(401);
      }
      $result = $this->company->fetch($id);
      if( ! $result ) {
          throw new RestException(404, 'Thirdparty not found');
      }
      if( ! DolibarrApi::_checkAccessToResource('societe',$this->company->id)) {
        throw new RestException(401, 'Access not allowed for login '.DolibarrApiAccess::$user->login);
      }
      return $this->company->delete($id);
    }

    /**
     * Get categories for a thirdparty
     *
     * @param int		$id         ID of thirdparty
     * @param string	$sortfield	Sort field
     * @param string	$sortorder	Sort order
     * @param int		$limit		Limit for list
     * @param int		$page		Page number
     *
     * @return mixed
     *
     * @url GET {id}/categories
     */
	function getCategories($id, $sortfield = "s.rowid", $sortorder = 'ASC', $limit = 0, $page = 0)
	{
		if (! DolibarrApiAccess::$user->rights->categorie->lire) {
			throw new RestException(401);
		}

		$categories = new Categorie($this->db);

		$result = $categories->getListForItem($id, 'customer', $sortfield, $sortorder, $limit, $page);

		if (empty($result)) {
			throw new RestException(404, 'No category found');
		}

		if ($result < 0) {
			throw new RestException(503, 'Error when retrieve category list : '.$categories->error);
		}

		return $result;
    }

    /**
     * Add category to a thirdparty
     *
     * @param int		$id	Id of thirdparty
     * @param array     $request_data   Request datas
     *
     * @return mixed
     *
     * @url POST {id}/addCategory
     */
    function addCategory($id, $request_data = NULL) {
        if (!isset($request_data["category_id"]))
            throw new RestException(400, "category_id field missing");
        $category_id = $request_data["category_id"];

      if(! DolibarrApiAccess::$user->rights->societe->creer) {
			  throw new RestException(401);
      }

      $result = $this->company->fetch($id);
      if( ! $result ) {
          throw new RestException(404, 'Thirdparty not found');
      }
      $category = new Categorie($this->db);
      $result = $category->fetch($category_id);
      if( ! $result ) {
          throw new RestException(404, 'category not found');
      }

      if( ! DolibarrApi::_checkAccessToResource('societe',$this->company->id)) {
        throw new RestException(401, 'Access not allowed for login '.DolibarrApiAccess::$user->login);
      }
      if( ! DolibarrApi::_checkAccessToResource('category',$category->id)) {
        throw new RestException(401, 'Access not allowed for login '.DolibarrApiAccess::$user->login);
      }

      $category->add_type($this->company,'customer');
      return $this->company;
    }


     /**
     * Get fixed amount discount of a thirdparty (all sources: deposit, credit note, commercial offers...)
     *
     * @param 	int 	$id             ID of the thirdparty
     * @param 	string 	$filter    	Filter exceptional discount. "none" will return every discount, "available" returns unapplied discounts, "used" returns applied discounts   {@choice none,available,used}
     * @param   string  $sortfield  	Sort field
     * @param   string  $sortorder  	Sort order
     *
     * @url     GET {id}/fixedamountdiscounts
     *
     * @return array  List of fixed discount of thirdparty
     *
     * @throws 400
     * @throws 401
     * @throws 404
     * @throws 503
     */
    function getFixedAmountDiscounts($id, $filter="none", $sortfield = "f.type", $sortorder = 'ASC')
    {
    	$obj_ret = array();

      if(! DolibarrApiAccess::$user->rights->societe->lire) {
        throw new RestException(401);
      }

      if(empty($id)) {
        throw new RestException(400, 'Thirdparty ID is mandatory');
      }

      if( ! DolibarrApi::_checkAccessToResource('societe',$id)) {
        throw new RestException(401, 'Access not allowed for login '.DolibarrApiAccess::$user->login);
      }

      $result = $this->company->fetch($id);
      if( ! $result ) {
          throw new RestException(404, 'Thirdparty not found');
      }


      $sql = "SELECT f.facnumber, f.type as factype, re.fk_facture_source, re.rowid, re.amount_ht, re.amount_tva, re.amount_ttc, re.description, re.fk_facture, re.fk_facture_line";
      $sql .= " FROM llx_societe_remise_except as re, llx_facture as f";
      $sql .= " WHERE f.rowid = re.fk_facture_source AND re.fk_soc = ".$id;
      if ($filter == "available")  $sql .= " AND re.fk_facture IS NULL AND re.fk_facture_line IS NULL";
      if ($filter == "used")  $sql .= " AND (re.fk_facture IS NOT NULL OR re.fk_facture_line IS NOT NULL)";

      $sql.= $this->db->order($sortfield, $sortorder);

      $result = $this->db->query($sql);
      if( ! $result ) {
          throw new RestException(503, $this->db->lasterror());
      } else {
          $num = $this->db->num_rows($result);
          while ( $obj = $this->db->fetch_object($result) ) {
              $obj_ret[] = $obj;
          }
      }

      return $obj_ret;
    }



    /**
     * Return list of invoices qualified to be replaced by another invoice.
     *
     * @param int   $id             Id of thirdparty
     *
     * @url     GET {id}/getinvoicesqualifiedforreplacement
     *
     * @return array
     * @throws 400
     * @throws 401
     * @throws 404
     * @throws 405
     */
    function getInvoicesQualifiedForReplacement($id) {

    	if(! DolibarrApiAccess::$user->rights->facture->lire) {
    		throw new RestException(401);
    	}
    	if(empty($id)) {
    		throw new RestException(400, 'Thirdparty ID is mandatory');
    	}

    	if( ! DolibarrApi::_checkAccessToResource('societe',$id)) {
    		throw new RestException(401, 'Access not allowed for login '.DolibarrApiAccess::$user->login);
    	}

    	/*$result = $this->thirdparty->fetch($id);
    	 if( ! $result ) {
    	 throw new RestException(404, 'Thirdparty not found');
    	 }*/

    	$invoice = new Facture($this->db);
    	$result = $invoice->list_replacable_invoices($id);
    	if( $result < 0) {
    		throw new RestException(405, $this->thirdparty->error);
    	}

    	return $result;
    }

    /**
     * Return list of invoices qualified to be corrected by a credit note.
     * Invoices matching the following rules are returned
     * (validated + payment on process) or classified (payed completely or payed partialy) + not already replaced + not already a credit note
     *
     * @param int   $id             Id of thirdparty
     *
     * @url     GET {id}/getinvoicesqualifiedforcreditnote
     *
     * @return array
     * @throws 400
     * @throws 401
     * @throws 404
     * @throws 405
     */
    function getInvoicesQualifiedForCreditNote($id) {

        if(! DolibarrApiAccess::$user->rights->facture->lire) {
                throw new RestException(401);
        }
        if(empty($id)) {
                throw new RestException(400, 'Thirdparty ID is mandatory');
        }

        if( ! DolibarrApi::_checkAccessToResource('societe',$id)) {
                throw new RestException(401, 'Access not allowed for login '.DolibarrApiAccess::$user->login);
        }

        /*$result = $this->thirdparty->fetch($id);
        if( ! $result ) {
                throw new RestException(404, 'Thirdparty not found');
        }*/

    	$invoice = new Facture($this->db);
        $result = $invoice->list_qualified_avoir_invoices($id);
        if( $result < 0) {
                throw new RestException(405, $this->thirdparty->error);
        }

        return $result;
    }


	/**
	 * Clean sensible object datas
	 *
	 * @param   object  $object    Object to clean
	 * @return    array    Array of cleaned object properties
	 */
	function _cleanObjectDatas($object) {

	    $object = parent::_cleanObjectDatas($object);

	    unset($object->nom);	// ->name already defined and nom deprecated

	    unset($object->total_ht);
	    unset($object->total_tva);
	    unset($object->total_localtax1);
	    unset($object->total_localtax2);
	    unset($object->total_ttc);

	    unset($object->lines);
	    unset($object->thirdparty);

	    return $object;
	}

	/**
     * Validate fields before create or update object
     *
     * @param array $data   Datas to validate
     * @return array
     *
     * @throws RestException
     */
    function _validate($data)
    {
        $thirdparty = array();
        foreach (Thirdparties::$FIELDS as $field) {
            if (!isset($data[$field]))
                throw new RestException(400, "$field field missing");
            $thirdparty[$field] = $data[$field];
        }
        return $thirdparty;
    }
}
