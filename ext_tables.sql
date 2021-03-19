#
# Table structure for table 'fe_users'
#
CREATE TABLE fe_users
(

    oidc_identifier varchar(255) DEFAULT '' NOT NULL

);

#
# Table structure for table 'be_users'
#
CREATE TABLE be_users
(

    oidc_identifier varchar(255) DEFAULT '' NOT NULL

);

#
# Table structure for table 'be_groups'
#
CREATE TABLE be_groups
(

    oidc_identifier varchar(255) DEFAULT '' NOT NULL

);
