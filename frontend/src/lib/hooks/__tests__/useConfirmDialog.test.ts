import { describe, it } from "node:test";
import assert from "node:assert/strict";
import {
  type ConfirmDialogState,
  INITIAL_CONFIRM_DIALOG_STATE,
  executeConfirmAction,
} from "../useConfirmDialog.ts";

describe("useConfirmDialog & executeConfirmAction Tests", () => {
  it("transitions isLoading -> closes dialog on successful action", async () => {
    let state: ConfirmDialogState = {
      ...INITIAL_CONFIRM_DIALOG_STATE,
      isOpen: true,
      title: "Delete VM",
      message: "Confirm delete?",
    };
    const loadingHistory: boolean[] = [];
    const errors: string[] = [];

    const result = await executeConfirmAction({
      isCurrentlyLoading: () => Boolean(state.isLoading),
      setState: (updater) => {
        state = updater(state);
        loadingHistory.push(Boolean(state.isLoading));
      },
      action: async () => {
        assert.equal(state.isLoading, true);
      },
      onError: (msg) => errors.push(msg),
    });

    assert.equal(result, true);
    assert.equal(state.isOpen, false);
    assert.equal(state.isLoading, false);
    assert.deepEqual(loadingHistory, [true, false]);
    assert.equal(errors.length, 0);
  });

  it("ignores duplicate invocations when already loading", async () => {
    let actionCalled = false;
    const result = await executeConfirmAction({
      isCurrentlyLoading: () => true,
      setState: () => {},
      action: () => {
        actionCalled = true;
      },
      onError: () => {},
    });

    assert.equal(result, false);
    assert.equal(actionCalled, false);
  });

  it("resets isLoading, keeps dialog open, and surfaces Error / ApiError messages on failure", async () => {
    let state: ConfirmDialogState = {
      ...INITIAL_CONFIRM_DIALOG_STATE,
      isOpen: true,
      title: "Release IP",
      message: "Confirm release?",
    };
    const errors: string[] = [];

    const failedResult = await executeConfirmAction({
      isCurrentlyLoading: () => Boolean(state.isLoading),
      setState: (updater) => {
        state = updater(state);
      },
      action: async () => {
        throw new Error("Too many requests");
      },
      onError: (msg) => errors.push(msg),
    });

    assert.equal(failedResult, false);
    assert.equal(state.isOpen, true);
    assert.equal(state.isLoading, false);
    assert.deepEqual(errors, ["Too many requests"]);
  });
});
