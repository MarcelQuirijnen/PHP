/* following lines INITIALLY have to be executed as root */
/*
CREATE DATABASE IF NOT EXISTS phptest;

CREATE USER 'phptest'@'localhost' IDENTIFIED BY 'phptest';
GRANT ALL PRIVILEGES ON *.phptest TO 'phptest'@'localhost' WITH GRANT OPTION;
FLUSH PRIVILEGES;

/* in all other cases */
/* mysql -u<mysql_admin> -p phptest < /path/to/this/file/color_voting.sql
*/

DROP TABLE IF EXISTS phptest.Colors;
CREATE TABLE phptest.Colors(
  col_ID       int(10)     NOT NULL PRIMARY KEY    AUTO_INCREMENT,
  col_name     varchar(20)     NULL DEFAULT NULL
);

DROP TABLE IF EXISTS phptest.Votes;
CREATE TABLE phptest.Votes(
  vot_ID         int(10)     NOT NULL PRIMARY KEY    AUTO_INCREMENT,
  vot_col_ID     int(10)         NULL DEFAULT NULL,
  vot_noof_votes int(10)         NULL DEFAULT NULL,
  vot_cit_name   varchar(50)     NULL DEFAULT NULL
);

/* insert some data */

INSERT INTO phptest.Colors (col_name) VALUES ('Red');     /* 1 */
INSERT INTO phptest.Colors (col_name) VALUES ('Orange');  /* 2 */
INSERT INTO phptest.Colors (col_name) VALUES ('Yellow');  /* 3 */
INSERT INTO phptest.Colors (col_name) VALUES ('Green');   /* 4 */
INSERT INTO phptest.Colors (col_name) VALUES ('Blue');    /* 5 */
INSERT INTO phptest.Colors (col_name) VALUES ('Indigo');  /* 6 */
INSERT INTO phptest.Colors (col_name) VALUES ('Violet');  /* 7 */

INSERT INTO phptest.Votes (vot_cit_name, vot_col_ID, vot_noof_votes) VALUES ('Anchorage', 5, 10000);
INSERT INTO phptest.Votes (vot_cit_name, vot_col_ID, vot_noof_votes) VALUES ('Anchorage', 3, 15000);
INSERT INTO phptest.Votes (vot_cit_name, vot_col_ID, vot_noof_votes) VALUES ('Brooklyn', 1, 100000);
INSERT INTO phptest.Votes (vot_cit_name, vot_col_ID, vot_noof_votes) VALUES ('Brooklyn', 5, 250000);
INSERT INTO phptest.Votes (vot_cit_name, vot_col_ID, vot_noof_votes) VALUES ('Detroit', 1, 160000);
INSERT INTO phptest.Votes (vot_cit_name, vot_col_ID, vot_noof_votes) VALUES ('Selma', 3, 15000);
INSERT INTO phptest.Votes (vot_cit_name, vot_col_ID, vot_noof_votes) VALUES ('Selma', 7, 5000);
INSERT INTO phptest.Votes (vot_cit_name, vot_col_ID, vot_noof_votes) VALUES ('Yellville', 6, 900);   /* small town :-) */
INSERT INTO phptest.Votes (vot_cit_name, vot_col_ID, vot_noof_votes) VALUES ('Yellville', 7, 10);

