.. include:: ../Includes.txt

.. _admin:

==================
For Administrators
==================

.. _admin-installation:

Installation
============

There are several ways to require and install this extension. We recommend to get this extension via
`composer <https://getcomposer.org/>`__.

.. _admin-installation-composer:

Via Composer
------------

If your TYPO3 instance is running in composer mode, you can simply require the extension by running:

.. code-block:: bash

   composer req fsg/oidc

.. _admin-globalConfiguration:

Global Configuration
====================

You have to add following parameters to the :php:`$GLOBALS['TYPO3_CONF_VARS']['FE']['cacheHash']['excludedParameters']`
configuration: `code`, `state`, `error_description` and `error`.

.. _admin-logging:

Logging
=======

All critical errors will be logged into the TYPO3 logfile.

Configuration
=============

.. toctree::
    :maxdepth: 3

    ExtensionConfiguration/Index

