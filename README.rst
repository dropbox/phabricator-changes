About
=====

The ``phabricator-changes`` project provides integration between `Phabricator <https://phabricator.com>`_ and
`Changes <https://github.com/dropbox/changes>`_.

Setup
-----

1. Drop the code into phabricator/src/extensions/
2. Add a symlink in webroot/rsrc/js to ./rsrc/js
3. Run bin/celerity map
4. Configure Changes via http://phabricator.example.com/config/group/changesconfigoptions/
5. Add a build step to fire off a Changes job.

.. note:: Changes relies on the repository URL matching on both sides to determine which builds should fire.

Contributing
------------

You'll need to ensure ``arc lint`` is run before commiting, which also should implicitly run ``arc liberate``.
