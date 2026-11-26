import { describe, it } from "node:test";
import assert from "node:assert/strict";
import {
  DEFAULT_SMTP_PORTS,
  getDefaultPortForEncryption,
  canToggleVirtualizationMode,
  isVirtualizationConfigValid,
  buildAdminSettingsPayload,
  type AdminSettingsFormValues,
} from "../src/components/settings/settingsSchema.ts";

const baseFormValues: AdminSettingsFormValues = {
  registrationEnabled: true,
  allowVm: true,
  allowContainers: true,
  frontendUrl: "https://panel.example.com",
  smtpEnabled: true,
  smtpHost: "smtp.example.com",
  smtpPort: 587,
  smtpEncryption: "tls",
  smtpUsername: "mailer@example.com",
  smtpPassword: "secret-password",
  smtpFromAddress: "noreply@example.com",
  smtpFromName: "Ognivo Panel",
};

describe("DEFAULT_SMTP_PORTS and getDefaultPortForEncryption", () => {
  it("maps each encryption mode to its standard port", () => {
    assert.deepEqual(DEFAULT_SMTP_PORTS, { tls: 587, ssl: 465, none: 25 });
    assert.equal(getDefaultPortForEncryption("tls"), 587);
    assert.equal(getDefaultPortForEncryption("ssl"), 465);
    assert.equal(getDefaultPortForEncryption("none"), 25);
  });
});

describe("canToggleVirtualizationMode", () => {
  it("returns false only when trying to disable the sole enabled mode", () => {
    assert.equal(canToggleVirtualizationMode(true, false), false);
    assert.equal(canToggleVirtualizationMode(true, true), true);
    assert.equal(canToggleVirtualizationMode(false, true), true);
    assert.equal(canToggleVirtualizationMode(false, false), true);
  });
});

describe("isVirtualizationConfigValid", () => {
  it("requires at least one virtualization mode to be enabled", () => {
    assert.equal(isVirtualizationConfigValid(false, false), false);
    assert.equal(isVirtualizationConfigValid(true, false), true);
    assert.equal(isVirtualizationConfigValid(false, true), true);
    assert.equal(isVirtualizationConfigValid(true, true), true);
  });
});

describe("buildAdminSettingsPayload", () => {
  it("trims string fields and preserves non-trimmed password", () => {
    const payload = buildAdminSettingsPayload({
      ...baseFormValues,
      frontendUrl: "  https://panel.example.com  ",
      smtpHost: "  smtp.example.com  ",
      smtpPort: "465",
      smtpEncryption: "ssl",
      smtpUsername: "  user@example.com  ",
      smtpPassword: "  secret-with-spaces  ",
      smtpFromAddress: "  from@example.com  ",
      smtpFromName: "  Ognivo Mailer  ",
    });

    assert.deepEqual(payload, {
      registration_enabled: true,
      allow_virtual_machines: true,
      allow_containers: true,
      frontend_url: "https://panel.example.com",
      smtp: {
        enabled: true,
        host: "smtp.example.com",
        port: 465,
        encryption: "ssl",
        username: "user@example.com",
        password: "  secret-with-spaces  ",
        from_address: "from@example.com",
        from_name: "Ognivo Mailer",
      },
    });
  });

  it("coerces empty or whitespace-only optional fields to undefined/null", () => {
    const payload = buildAdminSettingsPayload({
      ...baseFormValues,
      registrationEnabled: false,
      allowVm: false,
      allowContainers: true,
      frontendUrl: "   ",
      smtpEnabled: false,
      smtpHost: "   ",
      smtpPort: 25,
      smtpEncryption: "none",
      smtpUsername: "",
      smtpPassword: "",
      smtpFromAddress: "  ",
      smtpFromName: "",
    });

    assert.deepEqual(payload, {
      registration_enabled: false,
      allow_virtual_machines: false,
      allow_containers: true,
      frontend_url: undefined,
      smtp: {
        enabled: false,
        host: null,
        port: 25,
        encryption: "none",
        username: null,
        password: null,
        from_address: null,
        from_name: null,
      },
    });
  });

  it("preserves whitespace-only password when truthy and falls back to port 587 for falsy/NaN ports", () => {
    const whitespacePasswordPayload = buildAdminSettingsPayload({
      ...baseFormValues,
      smtpPassword: "   ",
      smtpPort: "",
    });
    assert.equal(whitespacePasswordPayload.smtp.password, "   ");
    assert.equal(whitespacePasswordPayload.smtp.port, 587);

    assert.equal(
      buildAdminSettingsPayload({ ...baseFormValues, smtpPort: 0 }).smtp.port,
      587
    );
    assert.equal(
      buildAdminSettingsPayload({ ...baseFormValues, smtpPort: "not-a-number" }).smtp.port,
      587
    );
    assert.equal(
      buildAdminSettingsPayload({ ...baseFormValues, smtpPort: Number.NaN }).smtp.port,
      587
    );
  });
});
