<!DOCTYPE html PUBLIC "-//W3C//DTD XHTML 1.0 Strict//EN" "http://www.w3.org/TR/xhtml1/DTD/xhtml1-strict.dtd">
<html xmlns="http://www.w3.org/1999/xhtml" lang="en" xml:lang="en">
<head>
<title>Office Directory</title>
<meta http-equiv="content-type" content="text/html; charset=utf-8" />
<meta name="author" content="Marcel Quirijnen">
<meta name="company" content="Little Sugar Creek Technology Services, Inc.">
</head>
<body class="yui-skin-sam" onLoad="doOnLoad();">
<!-- -->
<!-- http://docs.dhtmlx.com/doku.php?id=dhtmlxgrid:base_types -->
<!-- -->

<link rel="stylesheet" type="text/css" href="/dhtmlxSuite/dhtmlxGrid/codebase/dhtmlxgrid.css">
<link rel="stylesheet" type="text/css" href="/dhtmlxSuite/dhtmlxGrid/codebase/skins/dhtmlxgrid_dhx_skyblue.css">

<script  src="/dhtmlxSuite/dhtmlxGrid/codebase/dhtmlxcommon.js"></script>
<script  src="/dhtmlxSuite/dhtmlxGrid/codebase/dhtmlxgrid.js"></script>
<script  src="/dhtmlxSuite/dhtmlxGrid/codebase/dhtmlxgridcell.js"></script>
<script  src="/dhtmlxSuite/dhtmlxDataProcessor/codebase/dhtmlxdataprocessor.js"></script>
<!-- script  src="/dhtmlxSuite/dhtmlxDataProcessor/codebase/dhtmlxdataprocessor_debug.js"></script -->

<link href="/css/main.css" type="text/css" rel="stylesheet" />
<br />
<div align="center" class="DRmessage">
   <div id="title">.:: Office Directory ::.</div>
</div>

<div id="gridbox" style="width:1100px; height:600px; background-color:white;margin-left: 25px;"></div>

<div style="display:none">
   <div id="state_flt_box"><select style="width:100%" onclick="(arguments[0]||window.event).cancelBubble=true;" onChange="filterBy()"></select></div>
</div>

<table width="375" border="0">
    <tr><td colspan="2">&nbsp;</td></tr>
    <tr>
        <td width="50"></td>
        <td>
            <a href="#" onclick="add_r()">Add contact info</a><br />
            <a href="javascript:void(0)" onclick="mygrid.deleteSelectedItem()">Delete contact (selected row)</a>
        </td>
    </tr>
</table>
   
<script>
            function doOnLoad() {
                mygrid = new dhtmlXGridObject('gridbox');
                mygrid.setImagePath("/dhtmlxSuite/dhtmlxGrid/codebase/imgs/");
                mygrid.setColumnIds("name,spouse,address,zip,city,state,bdate,phone,cell,eighthundred,fax,ext,email,company");
                mygrid.setHeader("Name,Spouse,Address,Zip,City,State,Birthday,Phone,Cell,1-800#,Fax,Ext,Email,Company");
                mygrid.setInitWidths("160,100,180,50,130,100,90,150,150,150,150,50,200,200");
                mygrid.setColAlign("left,left,left,left,left,center,left,left,left,left,left,left,left,left");
                mygrid.setColTypes("ed,ed,ed,ed,ed,coro,ed,ed,ed,ed,ed,ed,ed,ed");
                mygrid.getCombo(5).put('ar', 'AR');
                mygrid.getCombo(5).put('tn', 'TN');                       
                mygrid.setColSorting("str,str,str,str,str,str,str,str,str,str,str,str,str,str");
                mygrid.setColumnColor("#d5f1ff,white,white,white,white,white,white,white,white,white,white,white,white,white");
                mygrid.setColumnMinWidth(50,0);
                mygrid.setSkin("dhx_skyblue");
                mygrid.init();
                mygrid.loadXML("office_dir_xml.php?action=select", function() {
                mygrid.attachHeader("#rspan,#rspan,#rspan,#rspan,#rspan,<div id='state_flt' style='padding-right:3px'></div>,#rspan,#rspan,#rspan,#rspan,#rspan,#rspan,#rspan,#rspan");
                       //set state filter field
                       var stateFlt = document.getElementById("state_flt").appendChild(document.getElementById("state_flt_box").childNodes[0]);
                       populateSelectWithState(stateFlt);
                       //block sorting and resize actions for second row
                       mygrid.hdr.rows[2].onmousedown=mygrid.hdr.rows[2].onclick=function(e){(e||event).cancelBubble=true;}
                       mygrid.setSizes();
                });
         
                myDataProcessor = new dataProcessor("office_dir_xml.php?action=update");
                //myDataProcessor.setVerificator(0, not_empty);  //name
                //myDataProcessor.setVerificator(2, not_empty);  //address
                //myDataProcessor.setVerificator(3, not_empty);  //zip
                //myDataProcessor.setVerificator(4, not_empty);  //city
                //myDataProcessor.setVerificator(5, not_empty);  //state
                myDataProcessor.attachEvent("onRowMark",function(id) {
                   if (this.is_invalid(id) == "invalid")
                      return false;
                   return true;
                });
                myDataProcessor.enableDataNames(true);     //will use names instead of indexes
                myDataProcessor.setDataColumns([true,true,true,true,true,true,true,true,true,true,true,true,true,true]); //mark which columns will trigger data update
                myDataProcessor.init(mygrid);              //link dataprocessor to the grid
            }
            
            function not_empty(value,id,ind) {
               if (value == "")
                  mygrid.setCellTextStyle(id,ind,"background-color:red;");
               return value != "";
            }
                
            function greater_0(value, id, ind) {
               if (parseFloat(value) <= 0)
                  mygrid.setCellTextStyle(id, ind, "background-color:yellow;");
               return parseFloat(value) > 0;
            }
            
            function add_r() {
               var ind1 = window.prompt('Contact name', '');
               if (ind1 === null || typeof ind1 == "undefined")
                   return;
               mygrid.addRow(99999, [ind1,'','','','','AR','','','','','','','',''], mygrid.getRowIndex(mygrid.getSelectedId()));
            }
            
            function filterBy(){
                var tVal = document.getElementById("state_flt").childNodes[0].value.toLowerCase();

                for(var i = 0; i < mygrid.getRowsNum(); i++) {
                    var tStr = mygrid.cells2(i,5).getValue().toString().toLowerCase();
                    if (tVal == "" || tStr.indexOf(tVal) == 0)
                        mygrid.setRowHidden(mygrid.getRowId(i),false);
                    else
                        mygrid.setRowHidden(mygrid.getRowId(i),true);
                }
            }

            function populateSelectWithState(selObj) {
                selObj.options.add(new Option("All States",""))
                var usedStateAr = new dhtmlxArray();
                for (var i = 0; i < mygrid.getRowsNum(); i++) {
                    var stateNm = mygrid.cells2(i,5).getValue();
                    if (usedStateAr._dhx_find(stateNm) == -1) {
                        selObj.options.add(new Option(stateNm,stateNm));
                        usedStateAr[usedStateAr.length] = stateNm;
                    }
                }
            }   

</script>

</body>
</html>
