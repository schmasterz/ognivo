# Security Policy

## Ognivo Security Policy

The security of Ognivo and the infrastructure it manages is a priority.

Ognivo is an open-source infrastructure management platform. It interacts with hypervisors, virtual machines, networks, storage systems, and other infrastructure components. Security vulnerabilities may therefore have an impact beyond the application itself.

We appreciate responsible security research and encourage security researchers, users, and contributors to report vulnerabilities privately.

---

## Supported Versions

Security fixes are provided for actively maintained releases.

| Version                    | Supported   |
| -------------------------- | ----------- |
| Latest stable release      | Yes         |
| Latest pre-release / Alpha | Best effort |
| Older releases             | No          |

Support status may change as the project evolves.

For production environments, we recommend using the latest supported stable release.

---

## Reporting a Vulnerability

**Please do not report security vulnerabilities through public GitHub Issues, Discussions, or pull requests.**

If you discover a potential security vulnerability, report it privately using one of the following methods:

- **GitHub Security Advisories:** [Report a vulnerability](https://github.com/schmasterz/ognivo/security/advisories/new)

### What to Include

Please provide as much information as possible:

- A description of the vulnerability.
- Affected Ognivo version or commit.
- Affected component or subsystem.
- Steps to reproduce the issue.
- Proof of concept, if available.
- Potential security impact.
- Suggested mitigation, if known.

Please avoid including real credentials, private keys, personal data, or production infrastructure information in your report.

---

## Response Process

Ognivo maintainers aim to follow this process:

1. **Acknowledgement:** Confirm receipt of the report.
2. **Initial assessment:** Determine the severity and affected components.
3. **Investigation:** Reproduce and validate the vulnerability.
4. **Remediation:** Develop and test a security fix.
5. **Release:** Publish a patched version or mitigation.
6. **Disclosure:** Coordinate public disclosure with the reporter.

We aim to acknowledge reports within 72 hours.

This is a response target, not a guaranteed service-level agreement (SLA). Actual response and remediation times depend on severity, complexity, available information, and maintainer availability.
If a report requires additional information, maintainers may contact the reporter for clarification.

We ask reporters to allow reasonable time for remediation before public disclosure.

---

## Vulnerability Severity

Security issues are evaluated according to their actual impact and exploitability.

The following categories provide general guidance:

| Severity | Examples                                                                                               |
| -------- | ------------------------------------------------------------------------------------------------------ |
| Critical | Remote code execution, authentication bypass with platform-wide impact, compromise of multiple tenants |
| High     | Privilege escalation, unauthorized hypervisor access, cross-tenant data access                         |
| Medium   | Unauthorized access to restricted resources, security control bypass, sensitive information exposure   |
| Low      | Limited information disclosure, security misconfiguration with restricted impact                       |

Severity classification is determined by the maintainers after investigation.

---

## Security Scope

The following areas are particularly important to Ognivo's security model.

### Authentication and Authorization

- Authentication bypass.
- Session and token compromise.
- Broken access control.
- Privilege escalation.
- Cross-tenant authorization failures.
- Insecure API endpoints.

### Hypervisor Communication

- mTLS authentication bypass.
- Certificate validation failures.
- Trust token reuse.
- Certificate rotation vulnerabilities.
- Unauthorized hypervisor registration.
- Server identity and fingerprint verification failures.

### Web Console and Console Proxy

- Unauthorized console access.
- Single-use ticket reuse.
- Ticket expiration bypass.
- WebSocket authentication weaknesses.
- Upstream certificate validation bypass.
- Cross-tenant console session access.

### Virtual Machines and Infrastructure

- Unauthorized VM operations.
- Snapshot isolation failures.
- Resource ownership bypass.
- Network isolation failures.
- IPAM allocation vulnerabilities.
- Quota enforcement bypass.

### Data Protection

- Sensitive data exposure.
- Encryption and key management weaknesses.
- Insecure secret storage.
- Database access control failures.
- Audit log integrity bypass.
- Unauthorized modification of security-relevant records.

### Background Jobs and Real-Time Communication

- Queue payload manipulation.
- Unauthorized event broadcasting.
- Reverb private channel authorization failures.
- Redis ticket or lock manipulation.
- Race conditions affecting provisioning or authorization.

### Deployment and Supply Chain

- Docker image vulnerabilities.
- Dependency vulnerabilities.
- Insecure default configuration.
- CI/CD pipeline compromise.
- Secret exposure in build artifacts.
- Insecure deployment configurations.

---

## Security Expectations for Contributors

All contributors are expected to follow secure development practices.

- Never commit secrets, credentials, API tokens, or private keys.
- Never disable authentication or authorization checks to simplify development.
- Validate and authorize resource ownership at the server boundary.
- Use parameterized database queries and safe input handling.
- Avoid logging passwords, tokens, private keys, and sensitive user data.
- Apply appropriate authentication and authorization to real-time channels.
- Validate TLS certificates and remote server identities.
- Preserve tenant isolation.
- Add security-focused tests for changes affecting authentication, authorization, networking, or infrastructure operations.

Security-sensitive changes should include appropriate tests and documentation.

---

## Responsible Disclosure

We ask security researchers to:

- Avoid accessing, modifying, or deleting data belonging to other users.
- Avoid disrupting production services.
- Avoid denial-of-service testing.
- Avoid social engineering, phishing, or physical attacks.
- Avoid accessing infrastructure without explicit authorization.
- Stop testing and report immediately if sensitive data is encountered.

Please perform security research only against systems you own or have explicit permission to test.

---

## Security Updates

Security fixes and relevant advisories will be published through:

- GitHub Security Advisories.
- Official Ognivo release notes.
- Relevant GitHub repository announcements.

Users are encouraged to monitor the repository and apply security updates promptly.

---

## Scope and Disclaimer

This policy applies to the Ognivo open-source project and its officially maintained components.

It does not automatically authorize security testing against third-party infrastructure, hosted deployments, hypervisors, or systems operated by other users.

The maintainers reserve the right to update this policy as the project evolves.

---

**Thank you for helping keep Ognivo and its users secure.**