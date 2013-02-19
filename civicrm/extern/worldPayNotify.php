<?php

/**
 * CiviCRM payment processor for WorldPay (closely based on the other processors).
 *
 * Copyright (C) 2011-2013 GreenNet Ltd (imac@gn.apc.org)
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU Affero General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU Affero General Public License for more details.
 *
 * You should have received a copy of the GNU Affero General Public License
 * along with this program.  If not, see <http://www.gnu.org/licenses/>.
 *
 */

session_start( );

require_once '../civicrm.config.php';
require_once 'CRM/Core/Config.php';


/* Cache the real UF, override it with the SOAP environment */
$config =& CRM_Core_Config::singleton();

require_once 'CRM/Core/Payment/WorldPayIPN.php';
$worldpayIPN = new CRM_Core_Payment_WorldPayIPN( );
$worldpayIPN->main(FALSE);

?>