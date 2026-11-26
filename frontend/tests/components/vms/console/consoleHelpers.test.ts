import { describe, it } from "node:test";
import assert from "node:assert/strict";
import {
  MAX_BOOT_LOG_CHARS,
  hasLoginPrompt,
  appendBootLogChunk,
  estimateTerminalDimensions,
  buildConsoleWebSocketUrl,
  formatConsoleCloseMessage,
  classifyConsoleError,
  shouldAutoConnectOnRunningTransition,
  type ConnectionStatus,
  type ErrorCategory,
} from "../../../../src/components/vms/console/consoleHelpers.ts";

describe("consoleHelpers - Boot-log & Login Prompt Detection", () => {
  it("detects standard and case-insensitive guest login prompts", () => {
    assert.equal(hasLoginPrompt("Ubuntu 24.04 LTS\nubuntu-24 login: "), true);
    assert.equal(hasLoginPrompt("my_vm.internal login:"), true);
    assert.equal(hasLoginPrompt("HOST01 LOGIN:   "), true);
    assert.equal(hasLoginPrompt("Starting systemd...\r\nnode-1 login: \r\n\r\n"), true);
  });

  it("strips CSI and OSC ANSI escape sequences before checking for login prompt", () => {
    const esc = String.fromCharCode(27);
    const bel = String.fromCharCode(7);

    const withCsi = `${esc}[2J${esc}[H${esc}[1;32mubuntu login:${esc}[0m \r\n`;
    assert.equal(hasLoginPrompt(withCsi), true);

    const withOscBel = `${esc}]0;ttyS0${bel}debian-12 login: `;
    assert.equal(hasLoginPrompt(withOscBel), true);

    const withOscSt = `${esc}]0;console${esc}\\alpine login: ${esc}[K`;
    assert.equal(hasLoginPrompt(withOscSt), true);
  });

  it("only inspects the trailing 512 characters and ignores non-prompt text", () => {
    assert.equal(hasLoginPrompt(""), false);
    assert.equal(hasLoginPrompt("login failed for user root"), false);
    assert.equal(hasLoginPrompt("Welcome to Ubuntu"), false);

    // A login prompt buried more than 512 chars from the end is ignored
    const oldPromptPlusNoise = "vm1 login: \n" + "x".repeat(600);
    assert.equal(hasLoginPrompt(oldPromptPlusNoise), false);

    // A login prompt at the end of a long buffer is still detected
    const longPrefixPlusPrompt = "y".repeat(1000) + "\nvm1 login: ";
    assert.equal(hasLoginPrompt(longPrefixPlusPrompt), true);
  });

  it("appends boot log chunks up to maxChars and freezes on cap or login prompt", () => {
    const r1 = appendBootLogChunk("", "Booting kernel...\n");
    assert.deepEqual(r1, { buffer: "Booting kernel...\n", frozen: false });

    const r2 = appendBootLogChunk(r1.buffer, "ubuntu login: ");
    assert.deepEqual(r2, {
      buffer: "Booting kernel...\nubuntu login: ",
      frozen: true,
    });

    // Empty chunk preserves buffer and computes frozen state accurately
    assert.deepEqual(appendBootLogChunk("hello", ""), {
      buffer: "hello",
      frozen: false,
    });
    assert.deepEqual(appendBootLogChunk("vm login: ", ""), {
      buffer: "vm login: ",
      frozen: true,
    });
    assert.deepEqual(appendBootLogChunk("12345", "", 5), {
      buffer: "12345",
      frozen: true,
    });

    // Truncates chunk to remaining capacity and freezes
    const truncated = appendBootLogChunk("abc", "defghij", 6);
    assert.deepEqual(truncated, { buffer: "abcdef", frozen: true });

    // Already at or above maxChars does not append more data
    const alreadyFull = appendBootLogChunk("abcdef", "xyz", 6);
    assert.deepEqual(alreadyFull, { buffer: "abcdef", frozen: true });

    assert.equal(MAX_BOOT_LOG_CHARS, 51200);
  });
});

describe("consoleHelpers - Terminal Sizing & WebSocket Protocol", () => {
  it("falls back to 80x24 when viewport is missing or non-positive", () => {
    assert.deepEqual(estimateTerminalDimensions(false), { cols: 80, rows: 24 });
    assert.deepEqual(estimateTerminalDimensions(true, null), { cols: 80, rows: 24 });
    assert.deepEqual(estimateTerminalDimensions(false, { width: 0, height: 800 }), {
      cols: 80,
      rows: 24,
    });
    assert.deepEqual(estimateTerminalDimensions(true, { width: 1200, height: -10 }), {
      cols: 80,
      rows: 24,
    });
  });

  it("calculates and clamps terminal dimensions for modal and fullscreen viewports", () => {
    // Desktop modal: modalWidth = min(1440 - 32, 1152) = 1152, modalHeight = 680
    // cols = floor((1152 - 24) / 9) = 125, rows = floor((550 - 24) / 17) = 30
    assert.deepEqual(
      estimateTerminalDimensions(false, { width: 1440, height: 900 }),
      { cols: 125, rows: 30 }
    );

    // Narrow non-fullscreen viewport clamps cols to minimum 80
    assert.deepEqual(
      estimateTerminalDimensions(false, { width: 400, height: 600 }),
      { cols: 80, rows: 30 }
    );

    // Large fullscreen viewport clamps to maximum 240x80
    assert.deepEqual(
      estimateTerminalDimensions(true, { width: 3000, height: 2000 }),
      { cols: 240, rows: 80 }
    );

    // Small fullscreen viewport clamps to minimum 80x24
    assert.deepEqual(
      estimateTerminalDimensions(true, { width: 500, height: 300 }),
      { cols: 80, rows: 24 }
    );
  });

  it("builds ws:// and wss:// URLs based on location protocol", () => {
    assert.equal(
      buildConsoleWebSocketUrl("/ws/console/123?driver=exec", {
        protocol: "https:",
        host: "panel.example.com",
      }),
      "wss://panel.example.com/ws/console/123?driver=exec"
    );

    assert.equal(
      buildConsoleWebSocketUrl("/ws/console/123", {
        protocol: "http:",
        host: "localhost:3000",
      }),
      "ws://localhost:3000/ws/console/123"
    );
  });

  it("formats WebSocket close messages and flags terminal errors", () => {
    assert.deepEqual(formatConsoleCloseMessage(4000, "", "Console session"), {
      message: "[Console session closed: idle timeout exceeded]",
      isTerminalError: true,
    });

    assert.deepEqual(formatConsoleCloseMessage(1009, "ignored", "Serial console"), {
      message: "[Serial console closed: message payload exceeded maximum limit]",
      isTerminalError: true,
    });

    assert.deepEqual(formatConsoleCloseMessage(1011, "", "Console session"), {
      message: "[Console session closed: hypervisor upstream connection error]",
      isTerminalError: true,
    });

    assert.deepEqual(formatConsoleCloseMessage(1000, "session ended", "Console session"), {
      message: "[Console session closed: session ended]",
      isTerminalError: false,
    });

    assert.deepEqual(formatConsoleCloseMessage(1006, "", "Serial console"), {
      message: "[Serial console disconnected]",
      isTerminalError: false,
    });
  });
});

describe("consoleHelpers - Error Classification", () => {
  it("classifies structural errors with numeric HTTP status codes", () => {
    const forbidden = classifyConsoleError({ status: 403, message: "Forbidden" });
    assert.equal(forbidden.category, "forbidden");
    assert.match(forbidden.message, /workload owner only/i);

    const serialInUse = classifyConsoleError({
      status: 409,
      message: "Serial console is already active for this VM",
    });
    assert.equal(serialInUse.category, "serial_in_use");
    assert.equal(serialInUse.message, "Serial console is already active for this VM");

    const serialInUseFallback = classifyConsoleError({
      status: 409,
      message: "Device already in use",
    });
    assert.equal(serialInUseFallback.category, "serial_in_use");

    const notRunning = classifyConsoleError({ status: 409, message: "" });
    assert.equal(notRunning.category, "not_running");
    assert.match(notRunning.message, /only available while the instance is running/i);

    const limitReached = classifyConsoleError({ status: 429, message: "Too many sessions" });
    assert.equal(limitReached.category, "limit_reached");
    assert.equal(limitReached.message, "Too many sessions");

    const notFoundByStatus = classifyConsoleError({ status: 404, message: "" });
    assert.equal(notFoundByStatus.category, "not_found");
    assert.match(notFoundByStatus.message, /could not be found/i);

    const notFoundByMessage = classifyConsoleError({
      status: 500,
      message: "Shell binary not found in guest",
    });
    assert.equal(notFoundByMessage.category, "not_found");
    assert.equal(notFoundByMessage.message, "Shell binary not found in guest");

    const genericStatus = classifyConsoleError({ status: 502, message: "Bad gateway" });
    assert.equal(genericStatus.category, "generic");
    assert.equal(genericStatus.message, "Bad gateway");

    const genericEmptyMsg = classifyConsoleError({ status: 500 });
    assert.equal(genericEmptyMsg.category, "generic");
    assert.equal(genericEmptyMsg.message, "An unexpected error occurred.");
  });

  it("classifies Error instances that carry a status property via status rules", () => {
    const apiErr = Object.assign(new Error("Access denied"), { status: 403 });
    const result = classifyConsoleError(apiErr);
    assert.equal(result.category, "forbidden");
  });

  it("classifies plain Error instances by message content", () => {
    assert.equal(classifyConsoleError(new Error("HTTP 403 error")).category, "forbidden");
    assert.equal(classifyConsoleError(new Error("Operation forbidden")).category, "forbidden");

    assert.equal(
      classifyConsoleError(new Error("Console already active")).category,
      "serial_in_use"
    );
    assert.equal(
      classifyConsoleError(new Error("TTY already in use")).category,
      "serial_in_use"
    );
    assert.equal(
      classifyConsoleError(new Error("Serial console locked")).category,
      "serial_in_use"
    );

    assert.equal(classifyConsoleError(new Error("Conflict 409")).category, "not_running");
    assert.equal(classifyConsoleError(new Error("VM is not running")).category, "not_running");

    assert.equal(classifyConsoleError(new Error("Rate limit 429")).category, "limit_reached");
    assert.equal(
      classifyConsoleError(new Error("Too many concurrent connections")).category,
      "limit_reached"
    );

    assert.equal(classifyConsoleError(new Error("Instance not found")).category, "not_found");

    const genericErr = classifyConsoleError(new Error("Unexpected network failure"));
    assert.deepEqual(genericErr, {
      category: "generic",
      message: "Unexpected network failure",
    });

    const emptyErr = classifyConsoleError(new Error(""));
    assert.deepEqual(emptyErr, {
      category: "generic",
      message: "Failed to initiate interactive console session.",
    });
  });

  it("returns generic fallback for non-Error unknown values", () => {
    for (const value of [null, undefined, "raw string", 42, { message: "no status" }]) {
      assert.deepEqual(classifyConsoleError(value), {
        category: "generic",
        message: "Failed to initiate interactive console session.",
      });
    }
  });
});

describe("consoleHelpers - Auto-connect on Running Transition", () => {
  const baseParams: {
    wasRunning: boolean;
    isRunning: boolean;
    pendingRunningTransition: boolean;
    hasWsUrl: boolean;
    sessionLoading: boolean;
    isInFlight: boolean;
    connectionStatus: ConnectionStatus;
    errorCategory: ErrorCategory | null;
  } = {
    wasRunning: false,
    isRunning: true,
    pendingRunningTransition: false,
    hasWsUrl: false,
    sessionLoading: false,
    isInFlight: false,
    connectionStatus: "connecting",
    errorCategory: null,
  };

  it("triggers auto-connect on stopped -> running transition when idle", () => {
    assert.equal(shouldAutoConnectOnRunningTransition(baseParams), true);
  });

  it("does not trigger when already running without not_running recovery or when stopped", () => {
    assert.equal(
      shouldAutoConnectOnRunningTransition({
        ...baseParams,
        wasRunning: true,
        isRunning: true,
      }),
      false
    );

    assert.equal(
      shouldAutoConnectOnRunningTransition({
        ...baseParams,
        wasRunning: false,
        isRunning: false,
      }),
      false
    );
  });

  it("handles not_running error recovery including deferred pendingRunningTransition", () => {
    assert.equal(
      shouldAutoConnectOnRunningTransition({
        ...baseParams,
        errorCategory: "not_running",
        wasRunning: false,
        isRunning: true,
      }),
      true
    );

    assert.equal(
      shouldAutoConnectOnRunningTransition({
        ...baseParams,
        errorCategory: "not_running",
        wasRunning: true,
        isRunning: true,
        pendingRunningTransition: true,
      }),
      true
    );

    assert.equal(
      shouldAutoConnectOnRunningTransition({
        ...baseParams,
        errorCategory: "not_running",
        wasRunning: true,
        isRunning: true,
        pendingRunningTransition: false,
      }),
      false
    );

    // Other error categories block auto-connect
    assert.equal(
      shouldAutoConnectOnRunningTransition({
        ...baseParams,
        errorCategory: "forbidden",
      }),
      false
    );
  });

  it("suppresses auto-connect while session is loading, in-flight, or actively connected", () => {
    assert.equal(
      shouldAutoConnectOnRunningTransition({
        ...baseParams,
        sessionLoading: true,
      }),
      false
    );

    assert.equal(
      shouldAutoConnectOnRunningTransition({
        ...baseParams,
        isInFlight: true,
      }),
      false
    );

    assert.equal(
      shouldAutoConnectOnRunningTransition({
        ...baseParams,
        hasWsUrl: true,
        connectionStatus: "connected",
      }),
      false
    );

    assert.equal(
      shouldAutoConnectOnRunningTransition({
        ...baseParams,
        hasWsUrl: true,
        connectionStatus: "connecting",
      }),
      false
    );

    // With not_running error and stale disconnected wsUrl, still recovers
    assert.equal(
      shouldAutoConnectOnRunningTransition({
        ...baseParams,
        errorCategory: "not_running",
        hasWsUrl: true,
        connectionStatus: "disconnected",
      }),
      true
    );
  });
});
