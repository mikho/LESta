# Mail

Mail is the final and separately gated capability. It covers Exim/Dovecot, DKIM, spam and antivirus integration, quotas, queue controls, TLS, abuse monitoring, and credential rotation. It cannot be enabled until the mail threat model and operational readiness review pass, and even then it is never turned on by default: an operator must explicitly opt in on a per-node basis after that review.
