# Security policy

Please **do not open a public issue** for a security problem. Use GitHub's private vulnerability
reporting instead (the **Security** tab of the repository, then **Report a vulnerability**).

Describe the issue, how to reproduce it, and its impact. We will acknowledge the report, keep you
informed, and credit you in the release notes if you wish.

Only the latest release receives security fixes.

When you run your own server: keep `rengaine.ini` readable by root and the web server only, put the site
behind HTTPS, keep the system up to date, and feed `securite.log` to fail2ban if the server is exposed to
the internet.
