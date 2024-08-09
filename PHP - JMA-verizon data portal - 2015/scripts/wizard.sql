
/*
 !! Watch out for ALTER TABLE statements at the bottom !!
 !! Need to be done only once !!

DROP TABLE base_gen_types;
DROP TABLE business_rules;
DROP TABLE DeviceAbbreviations;
DROP TABLE CardTypes;
DROP TABLE NetworkTypes;
DROP TABLE SlottingModules
DROP TABLE NS5400Interfaces
DROP TABLE SiteStates
*/

CREATE TABLE IF NOT EXISTS base_gen_types (
  id INTEGER UNSIGNED NOT NULL AUTO_INCREMENT,
	base_gen_type VARCHAR(20) NOT NULL,
	EditedBy VARCHAR(15),
	PRIMARY KEY (id)
);

insert into base_gen_types values (null, '1.0 Gen', null);
insert into base_gen_types values (null, '1.5 Gen', null);
insert into base_gen_types values (null, '2.0 Gen', null);

CREATE TABLE IF NOT EXISTS business_rules (
    id          INTEGER UNSIGNED NOT NULL AUTO_INCREMENT,
    rule_key    varchar(32) NOT NULL,
    rule_value  varchar(32) NOT NULL,
    PRIMARY KEY(id, rule_key)
);

insert into business_rules values (null, 'SiteName', '[a-zA-Z]{3}SN[0-9]{1,}');
insert into business_rules values (null, 'SiteType', '^[0-9]\.[0-9].[a-zA-Z].*$');
insert into business_rules values (null, 'MX960Slots', '14');
insert into business_rules values (null, 'NS5400Slots', '5');
insert into business_rules values (null, 'IPSchemaRows', '10');
insert into business_rules values (null, 'NodeDeviceNamesRows', '20');
insert into business_rules values (null, 'DataHasMarkup', '1');
insert into business_rules values (null, 'MPC1CardPositions', '10');
insert into business_rules values (null, 'DefaultMPC2TypeStr', 'xe');
insert into business_rules values (null, 'DefaultMPC1TypeStr', 'ge');
insert into business_rules values (null, 'DefaultSPMTypeStr', 'ge');
insert into business_rules values (null, 'DefaultVSNAbbrev', 'MULTI');
insert into business_rules values (null, 'DefaultLayer', 'L2');
insert into business_rules values (null, 'NS5400ManageRows', '3');
insert into business_rules values (null, 'DefaultMX960PortType', '1G-TX');
insert into business_rules values (null, 'DefaultMX960Speed', '1G/Full');
insert into business_rules values (null, 'DefaultNS5400PortType', '1G-TX');
insert into business_rules values (null, 'DefaultNS5400Speed', '1G/Full');
insert into business_rules values (null, 'DefaultMX960HostnameExt1', 'r01');
insert into business_rules values (null, 'DefaultMX960HostnameExt2', 'r02');
insert into business_rules values (null, 'DefaultNS5400HostnameExt1', 'fw01');
insert into business_rules values (null, 'DefaultNS5400HostnameExt2', 'fw02');
insert into business_rules values (null, 'DefaultNetmaskTagPostfix', 'netwk_mask');
insert into business_rules values (null, 'DefaultNS5400AddressTag', 'N/A');
insert into business_rules values (null, 'DefaultSiteGroup', 'Design');
/* JMA_admin can be a comma separated list of email addresses */
insert into business_rules values (null, 'JMA_Admin', 'example.com');
/* ProfileImageUpload must be a full path string starting at document root */
insert into business_rules values (null, 'ProfileImageUpload', '/files/userphotos/');
insert into business_rules values (null, 'ProfileImageSize', '2Mb max');
insert into business_rules values (null, 'ProfileImageExt', 'jpg,png,jpeg,tif,tiff');
/* About Us page */
insert into business_rules values (null, 'Version', '1.0.0');
insert into business_rules values (null, 'Support', '913-202-1950');
insert into business_rules values (null, 'Email', 'portal-support@example.com');
insert into business_rules values (null, 'Copyright1', '2014,2015 Example and Example,');
insert into business_rules values (null, 'Copyright2', 'All rights reserved.');
/* Default timezone */
insert into business_rules values (null, 'DefaultTimeZone', 'Americas/Chicago');


CREATE TABLE IF NOT EXISTS DeviceAbbreviations (
    id           INTEGER UNSIGNED NOT NULL AUTO_INCREMENT,
    abbrev       varchar(10) NOT NULL,
    abbrev_desc  varchar(30),
    PRIMARY KEY(id, abbrev)
);

insert into DeviceAbbreviations values (null, 'r01', '');
insert into DeviceAbbreviations values (null, 'r01-re0', '');
insert into DeviceAbbreviations values (null, 'r01-re1', '');
insert into DeviceAbbreviations values (null, 'r02', '');
insert into DeviceAbbreviations values (null, 'r02-re0', '');
insert into DeviceAbbreviations values (null, 'r02-re1', '');
insert into DeviceAbbreviations values (null, 'fw01', '');
insert into DeviceAbbreviations values (null, 'fw02', '');
insert into DeviceAbbreviations values (null, 'cm01', '');
insert into DeviceAbbreviations values (null, 'b3k01', '');
insert into DeviceAbbreviations values (null, 'b3k02', '');
insert into DeviceAbbreviations values (null, 'pa01', '');
insert into DeviceAbbreviations values (null, 'g1001', '');


CREATE TABLE IF NOT EXISTS CardTypes (
    id         INTEGER UNSIGNED NOT NULL AUTO_INCREMENT,
    card_type  varchar(30) NOT NULL,
    card_desc  varchar(50),
    category   varchar(10),
    PRIMARY KEY(id, card_type)
);

insert into CardTypes values (null, 'MPC2 + 2x4-port 10G fiber MIC', '', 'MX960');
insert into CardTypes values (null, 'MPC2 + 1x4-port 10G fiber MIC', '', 'MX960');
insert into CardTypes values (null, 'MPC1 + 40x 1G Port tx MIC', '', 'MX960');
insert into CardTypes values (null, 'MPC2 + 20x 1G MIC fiber', '', 'MX960');
insert into CardTypes values (null, 'not assigned', '', 'MX960');
insert into CardTypes values (null, '8 port 1G SPMs', '', 'NS5400');
insert into CardTypes values (null, '2 port 10G SPMs', '', 'NS5400');
insert into CardTypes values (null, 'not assigned', '', 'NS5400');


CREATE TABLE IF NOT EXISTS NetworkTypes (
    id            INTEGER UNSIGNED NOT NULL AUTO_INCREMENT,
    network_type  varchar(30) NOT NULL,
    network_desc  varchar(50),
    PRIMARY KEY(id, network_type)
);

insert into NetworkTypes values (null, 'Public IP', '');
insert into NetworkTypes values (null, 'Private IP', '');
insert into NetworkTypes values (null, 'IDN', '');
insert into NetworkTypes values (null, 'not assigned', '');


CREATE TABLE IF NOT EXISTS SlottingModules (
    id           INTEGER UNSIGNED NOT NULL AUTO_INCREMENT,
    module       varchar(30) NOT NULL,
    module_desc  varchar(50),
    category     varchar(10),
    PRIMARY KEY(id, module)
);

insert into SlottingModules values (null, 'Routing Engine 0', '', 'MX960');
insert into SlottingModules values (null, 'Routing Engine 1', '', 'MX960');
insert into SlottingModules values (null, 'Routing Engine VIP', '', 'MX960');
insert into SlottingModules values (null, 'Management Module', '', 'NS5400');
insert into SlottingModules values (null, 'Redundant Interfaces', '', 'NS5400');


CREATE TABLE IF NOT EXISTS NS5400Interfaces (
    id               INTEGER UNSIGNED NOT NULL AUTO_INCREMENT,
    iface            varchar(30) NOT NULL,
    portType         varchar(10),
    layer            varchar(10),
    connectedDevType varchar(10),
    portSpeed        varchar(10),
    category         varchar(30),
    PRIMARY KEY(id, iface)
);

insert into NS5400Interfaces values (null, 'HA1', '1G-SX', 'L2', 'fw02', '1G/Full', 'Management Module');
insert into NS5400Interfaces values (null, 'HA2', '1G-SX', 'L2', 'fw02', '1G/Full', 'Management Module');
insert into NS5400Interfaces values (null, 'MGT', '1G-TX', 'L3', 'idnr01', '1G/Full', 'Management Module');
insert into NS5400Interfaces values (null, 'Red1', '1G-TX', 'L3', 'r1&2', '1G/Full', 'Redundant Interfaces');


CREATE TABLE IF NOT EXISTS SiteStates (
    id              INTEGER UNSIGNED NOT NULL AUTO_INCREMENT,
    siteState       varchar(50) NOT NULL,
    PRIMARY KEY(id, siteState)
);

insert into SiteStates values (null, 'Staging equipment');
insert into SiteStates values (null, 'Install/Async Verification');
insert into SiteStates values (null, 'Site Visit/Base Config');
insert into SiteStates values (null, 'Node Device Config');
insert into SiteStates values (null, 'Network Verification');
insert into SiteStates values (null, 'Failover Pre-Verification: 25% Complete');
insert into SiteStates values (null, 'Failover Pre-Verification: 50% Complete');
insert into SiteStates values (null, 'Failover Pre-Verification: 75% Complete');
insert into SiteStates values (null, 'Failover Pre-Verification: 100% Complete');
insert into SiteStates values (null, 'Call Through Testing');
insert into SiteStates values (null, 'Accepted by ISNTS');

/************************************/
/* ALTER TABLE statements           */
/************************************/

/* SiteInfo */
alter table SiteInfo add column GenType varchar(20) not null;
alter table SiteInfo add column Address1 varchar(50) not null;
alter table SiteInfo add column Address2 varchar(50);
alter table SiteInfo add column City varchar(50) not null;
alter table SiteInfo add column Zip int(4) unsigned not null;
alter table SiteInfo add column State varchar(2) not null;
alter table SiteInfo add column SiteGroup varchar(20) not null;

/* MX960PortAssignments */
alter table MX960PortAssignments add column CardType varchar(50) not null;
alter table MX960PortAssignments add column VSNAbbrev varchar(10) not null;
alter table MX960PortAssignments modify column Layer varchar(5) not null;
alter table MX960PortAssignments add column Hostname varchar(20) not null;

/* NS5400PortAssignments */
alter table NS5400PortAssignments add column CardType varchar(50) not null;
alter table NS5400PortAssignments add column Hostname varchar(20) not null;

/* CardSlotting */
alter table CardSlotting add column Hostname varchar(20) not null;

/* IP_Public */
alter table IP_Public add column SubnetMask varchar(32) not null;

/* IP_Private */
alter table IP_Private add column SubnetMask varchar(32) not null;

/* IP_IDN */
alter table IP_IDN add column SubnetMask varchar(32) not null;
