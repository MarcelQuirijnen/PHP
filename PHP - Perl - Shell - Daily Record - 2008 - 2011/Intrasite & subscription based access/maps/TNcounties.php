<? 

function GenerateHeadingMarkup()
{
   echo '<!DOCTYPE HTML PUBLIC "-//W3C//DTD HTML 4.01 Transitional//EN" "http://www.w3.org/TR/html4/strict.dtd" />'."\n";
   echo '<HTML>'."\n";
   echo '<HEAD>'."\n";
   echo '<meta http-equiv="content-type" content="text/html; charset=utf-8" />'."\n";
   echo '<meta http-equiv="Content-language" content="en" />'."\n";
   echo '<meta name="author" content="Marcel Quirijnen" />'."\n";
   echo '<meta name="company" content="Little Sugar Creek Technology Services, Inc." />'."\n";

   echo '<TITLE>' . $_REQUEST['geo'] . ' Tennessee County Newspaper info</TITLE>'."\n";
   
   echo '<script type="text/javascript" src="/js/cvi_tip_lib.js"></script>'."\n";
   echo '<script type="text/javascript" src="/js/wz_jsgraphics.js"></script>'."\n";
   echo '<script type="text/javascript" src="/js/mapper.js"></script>'."\n";
	
   echo '<link rel="stylesheet" type="text/css" media="all" href="/css/gecko.css" />'."\n";
   echo '<link rel="stylesheet" type="text/css" href="/css/overlay.css" />'."\n";
   echo '<link rel="stylesheet" type="text/css" href="/css/tooltip.css" />'."\n";

   echo '<link href="/css/main.css" media="all" type="text/css" rel="stylesheet" />'."\n";

   echo '<!--[if IE]>'."\n";
   echo '	<link rel="stylesheet" type="text/css" href="/css/overlay_ie.css" />'."\n";
   echo '<![endif]-->'."\n";
   echo '<!--[if lt IE 7]>'."\n";
   echo '	<script type="text/javascript" src="/js/fixed.js"></script>'."\n";
   echo '	<style type="text/css">'."\n";
   echo '		.png { visibility: hidden; behavior: url(/js/iepngfix2.htc); }'."\n";
   echo '		#cvi_tooltip {'."\n";
   echo "			width:expression(this.offsetWidth>240?'240px':'auto');\n";
   echo '		}'."\n";
   echo '	</style>'."\n";
   echo '<![endif]-->'."\n";
   
   echo '</HEAD>'."\n";
}

function GenerateBodyMarkup()
{   
   echo '<body>'."\n";
 
   //include_once './include/chklogin.php';
   //include_once 'siteconfig.php';
   //include_once './head.php';
   
   $geo = strtolower($_REQUEST['geo']);
   echo '<br />'."\n";
   echo '<center>'."\n";
   echo '<div id="demo" class="inlet">'."\n";
   echo '<div style="cursor: crosshair;">'."\n";
   echo '<img id="tncounties" class="mapper noborder iopacity50 icolorff0000" alt="" src="/portfolio/images/' . $geo . 'tn.gif" usemap="#' . $geo . 'tn" border="0">'."\n";
   echo '</div>'."\n";
   
   GenerateGeoMarkup($geo);
   
}


//Display Holiday info 3 weeks before the holiday
function GenerateHolidayMarkup($county, $link)
{
   // are we near a holiday, ie within 2 weeks from now ?
   // if changed, change also in county_info.php
   $how_near = '3 WEEK';
   $year = '%Y';
   $mo_day = '%c-%d';
   
   $sql = sprintf("SELECT independence_day_info FROM TNnewspapers WHERE concat(date_format(now(),'%s'), '-',date_format(independence_day, '%s')) BETWEEN now() AND DATE_ADD(now(), INTERVAL %s) AND county='%s'", $year, $mo_day, $how_near, $county);
   $res = mysql_query($sql, $link);
   if ($res) {
      $res_row = mysql_fetch_assoc($res);
      if ($res_row['independence_day_info'] != '') {
         return '<br> - <font color=red>Independence Day<br>' . $res_row['independence_day_info'] . '</font>';
      }
   } else {
      $sql = sprintf("SELECT memorial_day_info FROM TNnewspapers WHERE concat(date_format(now(),'%s'), '-',date_format(memorial_day, '%s')) BETWEEN now() AND DATE_ADD(now(), INTERVAL %s) AND county='%s'", $year, $mo_day, $how_near, $county);
      $res = mysql_query($sql, $link);
      if ($res) {
         $res_row = mysql_fetch_assoc($res);
         if ($res_row['memorial_day_info'] != '') {
            return '<br> - <font color=red>Memorial Day<br>' . $res_row['memorial_day_info'] . '</font>';
         }
      } else {
         $sql = sprintf("SELECT labor_day_info FROM TNnewspapers WHERE concat(date_format(now(),'%s'), '-',date_format(labor_day, '%s')) BETWEEN now() AND DATE_ADD(now(), INTERVAL %s) AND county='%s'", $year, $mo_day, $how_near, $county);
         $res = mysql_query($sql, $link);
         if ($res) {
            $res_row = mysql_fetch_assoc($res);
            if ($res_row['labor_day_info'] != '') {
               return '<br> - <font color=red>Labor Day<br>' . $res_row['labor_day_info'] . '</font>';
            }
         } else {
            $sql = sprintf("SELECT veterans_day_info FROM TNnewspapers WHERE concat(date_format(now(),'%s'), '-',date_format(veterans_day, '%s')) BETWEEN now() AND DATE_ADD(now(), INTERVAL %s) AND county='%s'", $year, $mo_day, $how_near, $county);
            $res = mysql_query($sql, $link);
            if ($res) {
               $res_row = mysql_fetch_assoc($res);
               if ($res_row['veterans_day_info'] != '') {
                  return '<br> - <font color=red>Veterans Day<br>' . $res_row['veterans_day_info'] . '</font>';
               }
            } else {
               $sql = sprintf("SELECT thanksgiving_day_info FROM TNnewspapers WHERE concat(date_format(now(),'%s'), '-',date_format(thanksgiving_day, '%s')) BETWEEN now() AND DATE_ADD(now(), INTERVAL %s) AND county='%s'", $year, $mo_day, $how_near, $county);
               $res = mysql_query($sql, $link);
               if ($res) {
                  $res_row = mysql_fetch_assoc($res);
                  if ($res_row['thanksgiving_day_info'] != '') {
                     return '<br> - <font color=red>Thanksgiving Day<br>' . $res_row['thanksgiving_day_info'] . '</font>';
                  }
               } else {
                  $sql = sprintf("SELECT xmas_day_info FROM TNnewspapers WHERE concat(date_format(now(),'%s'), '-',date_format(xmas_day, '%s')) BETWEEN now() AND DATE_ADD(now(), INTERVAL %s) AND county='%s'", $year, $mo_day, $how_near, $county);
                  $res = mysql_query($sql, $link);
                  if ($res) {
                     $res_row = mysql_fetch_assoc($res);
                     if ($res_row['xmas_day_info'] != '') {
                        return '<br> - <font color=red>Christmas Day<br>' . $res_row['xmas_day_info'] . '</font>';
                     }   
                  } else {
                     $sql = sprintf("SELECT newyears_day_info FROM TNnewspapers WHERE concat(date_format(now(),'%s'), '-',date_format(newyears_day, '%s')) BETWEEN now() AND DATE_ADD(now(), INTERVAL %s) AND county='%s'", $year, $mo_day, $how_near, $county);
                     $res = mysql_query($sql, $link);
                     if ($res) {
                        $res_row = mysql_fetch_assoc($res);
                        if ($res_row['newyears_day_info'] != '') {
                           return '<br> - <font color=red>New Years Day<br>' . $res_row['newyears_day_info'] . '</font>';
                        }   
                     }
                  } 
               }
            }
         }
      }
   }
}

// Display holiday info when info is filled out in database.
// Don't care about time of year or holiday that's coming up
function GenerateHolidayMarkup_version2($county, $link)
{
   $sql = sprintf("SELECT independence_day_info, memorial_day_info, labor_day_info, newyears_day_info, veterans_day_info, thanksgiving_day_info, xmas_day_info FROM TNnewspapers WHERE county='%s'", $county);
   $res = mysql_query($sql, $link);
   if ($res) {
      $res_row = mysql_fetch_assoc($res);
      if ($res_row['independence_day_info'] != '') {
         return '<br> - <font color=red>Independence Day<br>' . $res_row['independence_day_info'] . '</font>';
      } if ($res_row['memorial_day_info'] != '') {
         return '<br> - <font color=red>Memorial Day<br>' . $res_row['memorial_day_info'] . '</font>';
      } if ($res_row['labor_day_info'] != '') {
         return '<br> - <font color=red>Labor Day<br>' . $res_row['labor_day_info'] . '</font>';
      } if ($res_row['veterans_day_info'] != '') {
         return '<br> - <font color=red>Veterans Day<br>' . $res_row['veterans_day_info'] . '</font>';
      } if ($res_row['thanksgiving_day_info'] != '') {
         return '<br> - <font color=red>Thanksgiving Day<br>' . $res_row['thanksgiving_day_info'] . '</font>';
      } if ($res_row['xmas_day_info'] != '') {
         return '<br> - <font color=red>Christmas Day<br>' . $res_row['xmas_day_info'] . '</font>';
      } if ($res_row['newyears_day_info'] != '') {
         return '<br> - <font color=red>New Years Day<br>' . $res_row['newyears_day_info'] . '</font>';
      }
   }
}


function GenerateGeoMarkup($geo)
{
   include_once 'siteconfig.php';
   $geo_ = sprintf("%s", mysql_real_escape_string($geo));
   
   echo '<map name="' . $geo_ . 'tn">'."\n";
   $sql = sprintf("SELECT * FROM TNnewspapers WHERE geo='%s'", $geo_);
   $counties = mysql_query($sql, $link);
   while ($county = mysql_fetch_assoc($counties)) {
      $holiday = GenerateHolidayMarkup_version2($county['county'], $link);
      $tooltip = $county['county'] . ' County' .
                 '<br> - Seat:' . $county['seat'] . 
                 '<br> - Pub dates:' . $county['pubdates'] .
                 '<br> - Deadline:' . $county['deadline'] .
                 '<br> - Door:' . $county['door'] .
                 '<br> - Officer:' . $county['officer'] .
                 $holiday;          
      //echo '	<area tooltip="' . $tooltip . '" class="noborder icolor00ff00" onmouseover="cvi_tip._show(event);" onmouseout="cvi_tip._hide(event);" onmousemove="cvi_tip._move(event);" shape="poly" coords="' . $county['coords'] . '" href="#" alt="' . $county['county'] . ' county">'."\n";   
      echo '	<area tooltip="' . $tooltip . '" class="noborder icolor00ff00" onmouseover="cvi_tip._show(event);" onmouseout="cvi_tip._hide(event);" onmousemove="cvi_tip._move(event);" shape="poly" coords="' . $county['coords'] . '" href="#" alt="' . $county['county'] . ' county" onClick="window.open(\'county_info.php?county='.$county['county'].'\',\'County Newspaper Info\',\'scrollbars=1,width=600,height=300\');">' . "\n";

   }
   switch($geo_) {
	 case "east":   
             echo '	<area tooltip="Middle Tennessee" class="noborder icolor00ff00" onmouseover="cvi_tip._show(event);" onmouseout="cvi_tip._hide(event);" onmousemove="cvi_tip._move(event);" shape="poly" coords="219,65,232,85,238,104,241,106,236,109,231,113,228,117,223,115,220,126,213,127,208,126,205,137,201,147,200,153,190,152,184,151,174,154,157,163,144,163,141,185,140,197,144,209,147,215,144,226,138,231,139,237,139,239,121,263,113,274,96,276,77,279,82,288,91,292,92,301,95,306,100,308,101,312,98,315,87,322,81,326,67,327,63,329,65,341,59,344,48,337,39,339,38,393,1,393,2,60" href="#" alt="Middle Tennessee">'."\n";
             echo '	<area tooltip="Kentucky" class="noborder icolor00ff00" onmouseover="cvi_tip._show(event);" onmouseout="cvi_tip._hide(event);" onmousemove="cvi_tip._move(event);" shape="poly" coords="2,1,4,56,411,66,469,2" href="#" alt="KY" title="Kentuky">'."\n";
             echo '	<area tooltip="Virginia" class="noborder icolor00ff00" onmouseover="cvi_tip._show(event);" onmouseout="cvi_tip._hide(event);" onmousemove="cvi_tip._move(event);" shape="poly" coords="472,3,421,64,482,63,495,65,706,63,712,59,762,61,762,69,791,70,789,2" href=#" alt="VA" title="Virginia">'."\n";
             echo '	<area tooltip="Georgia" class="noborder icolor00ff00" onmouseover="cvi_tip._show(event);" onmouseout="cvi_tip._hide(event);" onmousemove="cvi_tip._move(event);" shape="poly" coords="90,400,129,568,318,569,340,396" href="#" alt="GA" title="Georgia">'."\n";
             echo '	<area tooltip="North Carolina" class="noborder icolor00ff00" onmouseover="cvi_tip._show(event);" onmouseout="cvi_tip._hide(event);" onmousemove="cvi_tip._move(event);" shape="poly" coords="314,386,320,351,356,347,385,299,420,291,477,279,526,246,589,209,657,183,720,198,713,251,437,394" href="#" alt="NC" title="North Carolina">'."\n";
	         break;
	 case "middle": 
             echo '<area tooltip="West Tennessee" class="noborder icolor00ff00" onmouseover="cvi_tip._show(event);" onmouseout="cvi_tip._hide(event);" onmousemove="cvi_tip._move(event);" shape="poly" alt="West Tennessee" coords="140,65,146,94,148,119,156,134,162,149,164,165,169,173,159,190,153,194,152,210,164,220,160,238,145,261,144,270,145,284,153,298,153,306,146,309,144,315,144,321,149,338,148,349,152,349,157,350,155,375,155,403,1,403,3,57" href="#" title="West Tennessee">'."\n";
             echo '<area tooltip="East Tennessee" class="noborder icolor00ff00" onmouseover="cvi_tip._show(event);" onmouseout="cvi_tip._hide(event);" onmousemove="cvi_tip._move(event);" shape="poly" alt="East Tennessee" coords="705,79,726,124,713,136,712,140,705,140,702,149,690,150,686,163,683,165,684,175,677,178,672,177,662,174,658,174,647,182,643,184,628,186,626,202,625,204,625,214,633,223,631,237,625,247,624,249,627,254,620,265,620,265,611,276,600,289,594,297,570,299,575,304,578,314,577,317,586,321,585,332,579,340,575,340,574,346,551,350,549,360,548,365,539,369,524,362,520,366,520,410,793,411,789,79" href="#" title="East Tennessee">'."\n";
             echo '<area tooltip="Kentucky" class="noborder icolor00ff00" onmouseover="cvi_tip._show(event);" onmouseout="cvi_tip._hide(event);" onmousemove="cvi_tip._move(event);" shape="poly" alt="KY" coords="5,4,5,51,180,60,182,67,426,66,544,72,647,72,702,76,671,0,139,4,3,2" href="#" title="Kentucky">'."\n";
             echo '<area tooltip="Alabama" class="noborder icolor00ff00" onmouseover="cvi_tip._show(event);" onmouseout="cvi_tip._hide(event);" onmousemove="cvi_tip._move(event);" shape="poly" alt="AL" coords="156,409,152,577,374,575,427,415" href="#" title="Alabama">'."\n";
	         break;
     case "west":   
             echo '<area tooltip="Middle Tennessee" class="noborder icolor00ff00" onmouseover="cvi_tip._show(event);" onmouseout="cvi_tip._hide(event);" onmousemove="cvi_tip._move(event);" shape="poly" alt="Middle Tennessee" coords="577,110,579,130,589,138,597,159,603,179,597,193,589,202,591,212,590,218,599,222,600,233,601,239,591,254,591,263,585,271,586,282,584,290,591,300,595,314,591,321,583,322,583,325,593,330,593,337,592,339,586,340,588,349,592,355,594,398,593,411,594,415,791,413,790,109" href=#" title="Middle Tennessee">'."\n";
             echo '<area tooltip="Missouri" class="noborder icolor00ff00" onmouseover="cvi_tip._show(event);" onmouseout="cvi_tip._hide(event);" onmousemove="cvi_tip._move(event);" shape="poly" alt="MO" coords="294,199,221,200,240,114,2,110,5,2,384,2,320,113,313,122,320,132,319,137,305,138,303,148,309,154,311,158,289,157,297,174,304,183" href="#" title="Missouri">'."\n";
             echo '<area tooltip="Arkansas" class="noborder icolor00ff00" onmouseover="cvi_tip._show(event);" onmouseout="cvi_tip._hide(event);" onmousemove="cvi_tip._move(event);" shape="poly" alt="AR" coords="2,114,235,117,216,205,289,207,284,220,290,225,275,235,283,245,266,256,252,258,246,271,250,279,254,285,245,289,246,294,231,304,233,315,225,313,205,332,218,345,219,349,209,354,213,365,223,368,226,384,212,384,206,401,186,402,187,418,181,425,172,447,129,615,2,614" href="#" title="Arkansas">'."\n";
             echo '<area tooltip="Kentucky" class="noborder icolor00ff00" onmouseover="cvi_tip._show(event);" onmouseout="cvi_tip._hide(event);" onmousemove="cvi_tip._move(event);" shape="poly" alt="KY" coords="386,4,327,106,789,105,787,2" href="#" title="Kentuky">'."\n";
             echo '<area tooltip="Mississippi" class="noborder icolor00ff00" onmouseover="cvi_tip._show(event);" onmouseout="cvi_tip._hide(event);" onmousemove="cvi_tip._move(event);" shape="poly" alt="MS" coords="190,421,548,420,554,429,553,616,134,613,178,438" href="#" title="Mississippi">'."\n";
             echo '<area tooltip="Alabama" class="noborder icolor00ff00" onmouseover="cvi_tip._show(event);" onmouseout="cvi_tip._hide(event);" onmousemove="cvi_tip._move(event);" shape="poly" alt="AL" coords="559,419,791,418,789,615,557,616,558,433" href="#" title="Alabama">'."\n";  
             break; 
   }	 
   echo '</map>'."\n";
   
}


/////////////////////////////////
// Start of code 
/////////////////////////////////

GenerateHeadingMarkup();
GenerateBodyMarkup();

echo '</div>'."\n"; // end of demo div
echo '</BODY>'."\n";
echo '</HTML>'."\n";
?>
