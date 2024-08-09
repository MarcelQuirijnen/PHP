<?php

Namespace Portal\Controller;

class Home {  // can't call this Default, it's a reserved word

   public static function default_controller() {
       global $template, $app;

       // read business rules
       $businessRules = \ORM::for_table('business_rules')->raw_query('select rule_key, rule_value from business_rules order by id asc')->find_many();
       foreach($businessRules as $rule) {
           $_SESSION['rules'][$rule->rule_key] = $rule->rule_value;
       }
       $user = \ORM::for_table('users')->find_one($_SESSION['user']['id']);
       $_SESSION['user']['image'] = $user['image'] ? $user['image'] : 0;

       date_default_timezone_set($_SESSION['rules']['DefaultTimeZone']);

       print $template->render('html/index.html.twig');
   }

}