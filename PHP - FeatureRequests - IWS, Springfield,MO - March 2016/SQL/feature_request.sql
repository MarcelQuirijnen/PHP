/* following 4 lines have to be executed as root/DBA */
/*
CREATE DATABASE IF NOT EXISTS Features;
CREATE USER 'features'@'localhost' IDENTIFIED BY 'features';
GRANT ALL PRIVILEGES ON Features.* TO 'features'@'localhost' WITH GRANT OPTION;
FLUSH PRIVILEGES;

In all other cases :
 PPSSTT : I know we shouldn't specify the password this way, but ... 
 mysql -ufeatures -pfeatures Features < feature_request.sql
*/

/* 
   I prefer to not use FKs and put the responsibility of integrity in the application 
   and since I'm using MySQL's default storage, it isn't even supported
*/

/* best practices says to use singular table name */
DROP TABLE IF EXISTS Features.Request;
CREATE TABLE Features.Request(
  id             int(10)      NOT NULL AUTO_INCREMENT,
  Title          varchar(50)  NOT NULL,
  Description    varchar(255) NULL DEFAULT NULL,
  Client         smallint     UNSIGNED DEFAULT 0,   /* could specify a FK here */
  ClientPriority tinyint      UNSIGNED DEFAULT 0,
  TargetDate     Date,
  Url            text,
  ProductArea    ENUM('Policies', 'Billing', 'Claims', 'Reports'),
  created        datetime     NOT NULL,
  modified       timestamp    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id)
) ENGINE=MyISAM  DEFAULT CHARSET=latin1;


DROP TABLE IF EXISTS Features.Client;
CREATE TABLE Features.Client(
  id         int(10)     NOT NULL AUTO_INCREMENT,
  name       varchar(50) NOT NULL,
  created    datetime    NOT NULL,
  modified   timestamp   NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id) 
) ENGINE=MyISAM  DEFAULT CHARSET=latin1;

/* create a view to make our lives/queries a bit easier (replace Client id with its Name) */
DROP VIEW IF EXISTS Features.ClientRequest;
CREATE VIEW Features.ClientRequest AS
    SELECT a.id, a.Title, a.Description, b.Name as Client, a.ClientPriority, a.TargetDate, a.Url, a.ProductArea, a.created, a.modified
    FROM Features.Request a, Features.Client b
    WHERE a.Client = b.id
    ORDER BY b.id ASC;


/* insert some data */
/* a few clients of us */
INSERT INTO Features.Client VALUES (null, 'IWS', now(), null);
INSERT INTO Features.Client VALUES (null, 'Pythion Castle', now(), null);
INSERT INTO Features.Client VALUES (null, 'Silver Dollar City', now(), null);
INSERT INTO Features.Client VALUES (null, 'Branson Belle', now(), null);
INSERT INTO Features.Client VALUES (null, 'Alibaba', now(), null);

/* and some of their prior feature requests */
INSERT INTO Features.Request VALUES (null, 'Rounded corners on buttons','Replace right angle corners on buttons with bootstrap rounded ones.', 1, 1, CURDATE()+20,'', 'Policies', now(), null);
INSERT INTO Features.Request VALUES (null, 'Rectangular corners on buttons','Get rid of them ugly round things on my buttons.', 1, 2, CURDATE()+30,'', 'Policies', now(), null);
INSERT INTO Features.Request VALUES (null, 'Add a discount feature to invoicing','Add discount option on invoicing', 4, 2, CURDATE()+50,'', 'Billing', now(), null);
INSERT INTO Features.Request VALUES (null, 'Add a coupon feature to invoicing','Add coupon code option on invoicing', 4, 3, CURDATE()+51,'', 'Billing', now(), null);
INSERT INTO Features.Request VALUES (null, 'Put an URGENT stamp image using chinese charactes to claim','Put a big red URGENT stamp in chinese language at the top of the claim page', 5, 1, CURDATE()+5,'', 'Claims', now(), null);
