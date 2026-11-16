# Contributing to Ognivo

First of all, thank you for your interest in contributing to Ognivo!

We welcome contributions of all kinds, including bug reports, feature requests, documentation improvements, tests, and code contributions.

This document explains how to get involved.

## Code of Conduct

By participating in this project, you agree to be respectful, constructive, and considerate of others.

We expect all contributors to help maintain a welcoming and collaborative environment.

## Before You Start

Before submitting a contribution:

- Check existing issues and pull requests to avoid duplicate work.
- For significant changes, open an issue first to discuss the proposed approach.
- Keep changes focused and avoid unrelated modifications.
- Make sure your contribution does not introduce unnecessary breaking changes.

## Reporting Bugs

If you find a bug, please open a GitHub issue.

Include as much relevant information as possible:

- A clear description of the problem.
- Steps to reproduce the issue.
- Expected behavior.
- Actual behavior.
- Relevant logs or error messages.
- Environment details, such as OS, Docker version, and application version.

**Do not report security vulnerabilities in public issues.**

Please follow the instructions in [SECURITY.md](SECURITY.md).

## Suggesting Features

Feature requests are welcome.

When suggesting a feature, please describe:

- The problem you are trying to solve.
- The proposed solution.
- Why the feature would be useful.
- Any relevant examples or alternatives.

For larger architectural changes, please discuss the idea before starting implementation.

## Development Setup

Refer to the project documentation for installation and development instructions.

Before submitting code, make sure your local environment is configured correctly and the application runs as expected.

## Making Changes

When working on a contribution:

- Follow the existing project structure and coding conventions.
- Prefer small, focused pull requests.
- Avoid introducing unnecessary dependencies.
- Add or update tests when changing application behavior.
- Update documentation when introducing or changing functionality.
- Do not commit secrets, credentials, private keys, certificates, or environment-specific configuration.
- Avoid unrelated formatting or refactoring changes.

## Code Quality

Before submitting a pull request, run the relevant checks for the areas you changed.

These may include:

- Backend tests and static analysis.
- Frontend linting and type checking.
- Frontend tests.
- Build verification.

Contributions should not introduce new failing tests, lint errors, or type errors.

## Pull Requests

When opening a pull request:

1. Make sure your branch is up to date with the target branch.
2. Provide a clear title and description.
3. Explain what changed and why.
4. Reference related issues, if applicable.
5. Include testing details.
6. Mention any breaking changes or migration requirements.

Keep pull requests focused on one logical change whenever possible.

Pull requests may require review and changes before they are merged.

## Commit Messages

Use clear and descriptive commit messages.

Examples:

- `feat: add snapshot scheduling`
- `fix: validate hypervisor certificates`
- `docs: improve installation guide`
- `test: add quota service coverage`
- `refactor: simplify network allocation`

Conventional Commit-style messages are encouraged.

## Documentation

Documentation contributions are just as valuable as code contributions.

Please keep documentation:

- Clear and easy to understand.
- Consistent with the actual application behavior.
- Up to date with configuration and API changes.

## License

By submitting a contribution, you agree that your contributions will be licensed under the same license as this project.

## Questions

If you are unsure about something, feel free to open a GitHub issue for discussion.

Thank you for helping make Ognivo better!