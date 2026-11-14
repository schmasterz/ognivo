"use client";

import React, { useState, useEffect } from "react";
import { X, Server, Layers } from "lucide-react";
import { api, HostNode, ImageItem } from "@/lib/api";
import { useModalEscape } from "@/lib/hooks/useModalEscape";

interface CreateImageModalProps {
  image?: ImageItem | null;
  hosts: HostNode[];
  onClose: () => void;
  onSuccess: () => void;
}

export function CreateImageModal({
  image,
  hosts,
  onClose,
  onSuccess,
}: CreateImageModalProps) {
  const isEditing = Boolean(image);

  const [name, setName] = useState(image?.name ?? "");
  const [source, setSource] = useState(image?.source ?? "https://images.linuxcontainers.org");
  const [protocol, setProtocol] = useState(image?.protocol ?? "simplestreams");
  const [osType, setOsType] = useState<"linux" | "windows" | "bsd" | "other">(image?.os_type ?? "linux");
  const [enabled, setEnabled] = useState(image ? image.enabled : true);
  const [selectedHostIds, setSelectedHostIds] = useState<number[]>(
    image?.host_ids ?? image?.hosts?.map((h) => h.id) ?? hosts.map((h) => h.id)
  );

  const [loading, setLoading] = useState(false);
  const [error, setError] = useState<string | null>(null);

  useModalEscape(onClose, !loading);

  useEffect(() => {
    queueMicrotask(() => {
      setName(image?.name ?? "");
      setSource(image?.source ?? "https://images.linuxcontainers.org");
      setProtocol(image?.protocol ?? "simplestreams");
      setOsType(image?.os_type ?? "linux");
      setEnabled(image ? image.enabled : true);
      setSelectedHostIds(
        image?.host_ids ?? image?.hosts?.map((h) => h.id) ?? hosts.map((h) => h.id)
      );
      setError(null);
      setLoading(false);
    });
  }, [image, hosts]);

  const toggleHost = (hostId: number) => {
    setSelectedHostIds((prev) =>
      prev.includes(hostId) ? prev.filter((id) => id !== hostId) : [...prev, hostId]
    );
  };

  const selectAllHosts = () => {
    setSelectedHostIds(hosts.map((h) => h.id));
  };

  const clearAllHosts = () => {
    setSelectedHostIds([]);
  };

  const handleSubmit = async (e: React.FormEvent) => {
    e.preventDefault();
    setError(null);

    if (!name.trim()) {
      setError("Image name/alias is required.");
      return;
    }

    if (!source.trim()) {
      setError("Image source URL is required.");
      return;
    }

    setLoading(true);

    try {
      if (isEditing && image) {
        await api.adminUpdateImage(image.id, {
          name: name.trim(),
          source: source.trim(),
          protocol: protocol.trim(),
          os_type: osType,
          enabled,
          host_ids: selectedHostIds,
        });
      } else {
        await api.adminCreateImage({
          name: name.trim(),
          source: source.trim(),
          protocol: protocol.trim(),
          os_type: osType,
          enabled,
          host_ids: selectedHostIds,
        });
      }

      onSuccess();
      onClose();
    } catch (err: unknown) {
      if (err instanceof Error) {
        setError(err.message);
      } else {
        setError("Failed to save image.");
      }
    } finally {
      setLoading(false);
    }
  };

  return (
    <div className="fixed inset-0 z-50 flex items-center justify-center p-4 bg-black/60 backdrop-blur-sm animate-in fade-in duration-200">
      <div className="bg-white dark:bg-gray-900 border border-gray-200 dark:border-gray-800 rounded-3xl w-full max-w-lg overflow-hidden shadow-2xl relative transition-colors">
        {/* Header */}
        <div className="flex items-center justify-between px-6 py-4 border-b border-gray-200 dark:border-gray-800">
          <div className="flex items-center space-x-3">
            <div className="p-2 bg-blue-500/10 dark:bg-blue-500/20 text-blue-600 dark:text-blue-400 rounded-xl">
              <Layers className="w-5 h-5" />
            </div>
            <div>
              <h2 className="text-base font-bold text-gray-900 dark:text-white">
                {isEditing ? "Edit Approved Image" : "Add Approved Image"}
              </h2>
              <p className="text-xs text-gray-500 dark:text-gray-400">
                Configure OS image allowlist and compute node availability
              </p>
            </div>
          </div>
          <button
            onClick={onClose}
            className="text-gray-400 hover:text-gray-600 dark:hover:text-gray-200 transition"
          >
            <X className="w-5 h-5" />
          </button>
        </div>

        {/* Form Body */}
        <form onSubmit={handleSubmit} className="p-6 space-y-4 max-h-[80vh] overflow-y-auto">
          {error && (
            <div className="p-3 bg-red-500/10 border border-red-500/20 text-red-600 dark:text-red-400 text-xs rounded-xl">
              {error}
            </div>
          )}

          {/* Image Name / Alias */}
          <div>
            <label className="block text-xs font-semibold text-gray-700 dark:text-gray-300 mb-1">
              Image Name / Alias *
            </label>
            <input
              type="text"
              required
              value={name}
              onChange={(e) => setName(e.target.value)}
              placeholder="e.g. ubuntu/24.04, debian/12, rocky/9, windows/server-2022"
              className="w-full px-3.5 py-2.5 bg-gray-50 dark:bg-gray-800/60 border border-gray-200 dark:border-gray-700 rounded-xl text-xs text-gray-900 dark:text-white focus:outline-none focus:ring-2 focus:ring-blue-500 font-mono"
            />
          </div>

          {/* OS Type Selector */}
          <div>
            <label className="block text-xs font-semibold text-gray-700 dark:text-gray-300 mb-1.5">
              Operating System Family
            </label>
            <div className="grid grid-cols-4 gap-2">
              {(["linux", "windows", "bsd", "other"] as const).map((type) => (
                <button
                  key={type}
                  type="button"
                  onClick={() => setOsType(type)}
                  className={`py-2 px-3 rounded-xl border text-xs font-medium capitalize transition ${
                    osType === type
                      ? "bg-blue-50 dark:bg-blue-600/15 border-blue-500 text-blue-600 dark:text-blue-400 font-bold"
                      : "bg-gray-50 dark:bg-gray-800/40 border-gray-200 dark:border-gray-700/60 text-gray-600 dark:text-gray-400 hover:border-gray-300"
                  }`}
                >
                  {type}
                </button>
              ))}
            </div>
            {osType !== "linux" ? (
              <p className="text-[11px] text-amber-500 mt-1.5">
                Note: Non-Linux images can only run on Virtual Machines (KVM), not in LXC containers.
              </p>
            ) : (
              <p className="text-[11px] text-emerald-500 mt-1.5">
                Compatible with both Virtual Machines (KVM) and Lightweight Containers (LXC).
              </p>
            )}
          </div>

          {/* Source Server & Protocol */}
          <div className="grid grid-cols-3 gap-3">
            <div className="col-span-2">
              <label className="block text-xs font-semibold text-gray-700 dark:text-gray-300 mb-1">
                Source Server URL *
              </label>
              <input
                type="text"
                required
                value={source}
                onChange={(e) => setSource(e.target.value)}
                placeholder="https://images.linuxcontainers.org"
                className="w-full px-3.5 py-2.5 bg-gray-50 dark:bg-gray-800/60 border border-gray-200 dark:border-gray-700 rounded-xl text-xs text-gray-900 dark:text-white focus:outline-none focus:ring-2 focus:ring-blue-500 font-mono"
              />
            </div>
            <div>
              <label className="block text-xs font-semibold text-gray-700 dark:text-gray-300 mb-1">
                Protocol
              </label>
              <input
                type="text"
                value={protocol}
                onChange={(e) => setProtocol(e.target.value)}
                placeholder="simplestreams"
                className="w-full px-3.5 py-2.5 bg-gray-50 dark:bg-gray-800/60 border border-gray-200 dark:border-gray-700 rounded-xl text-xs text-gray-900 dark:text-white focus:outline-none focus:ring-2 focus:ring-blue-500 font-mono"
              />
            </div>
          </div>

          {/* Compute Nodes Association (Multi-Select with X) */}
          <div>
            <div className="flex items-center justify-between mb-1.5">
              <label className="text-xs font-semibold text-gray-700 dark:text-gray-300">
                Available Compute Nodes ({selectedHostIds.length} of {hosts.length})
              </label>
              <div className="flex items-center space-x-2 text-[11px]">
                <button
                  type="button"
                  onClick={selectAllHosts}
                  className="text-blue-600 dark:text-blue-400 hover:underline"
                >
                  All
                </button>
                <span className="text-gray-300 dark:text-gray-600">|</span>
                <button
                  type="button"
                  onClick={clearAllHosts}
                  className="text-gray-500 dark:text-gray-400 hover:underline"
                >
                  Clear
                </button>
              </div>
            </div>

            {/* Selected Badges with X */}
            {selectedHostIds.length > 0 ? (
              <div className="flex flex-wrap gap-1.5 p-2 bg-gray-50 dark:bg-gray-800/40 border border-gray-200 dark:border-gray-700/60 rounded-xl mb-2 max-h-24 overflow-y-auto">
                {hosts
                  .filter((h) => selectedHostIds.includes(h.id))
                  .map((host) => (
                    <span
                      key={host.id}
                      className="inline-flex items-center space-x-1 px-2.5 py-1 bg-blue-50 dark:bg-blue-600/20 text-blue-700 dark:text-blue-300 border border-blue-200 dark:border-blue-500/30 rounded-lg text-[11px] font-medium"
                    >
                      <Server className="w-3 h-3 text-blue-500" />
                      <span>{host.name}</span>
                      <button
                        type="button"
                        onClick={() => toggleHost(host.id)}
                        className="hover:bg-blue-200 dark:hover:bg-blue-500/40 rounded p-0.5 ml-1 transition"
                        title="Remove compute node association"
                      >
                        <X className="w-3 h-3 text-blue-600 dark:text-blue-300" />
                      </button>
                    </span>
                  ))}
              </div>
            ) : (
              <div className="p-2.5 bg-amber-50 dark:bg-amber-500/10 border border-amber-200 dark:border-amber-500/20 rounded-xl mb-2 text-[11px] text-amber-700 dark:text-amber-300">
                Warning: No compute nodes selected. This image will not be available for installation anywhere.
              </div>
            )}

            {/* Compute Nodes Checklist */}
            <div className="space-y-1.5 max-h-36 overflow-y-auto p-2 border border-gray-200 dark:border-gray-800 rounded-xl bg-gray-50/50 dark:bg-gray-900/50">
              {hosts.map((host) => {
                const isChecked = selectedHostIds.includes(host.id);
                return (
                  <label
                    key={host.id}
                    className="flex items-center justify-between p-2 rounded-lg hover:bg-gray-100 dark:hover:bg-gray-800/60 cursor-pointer text-xs"
                  >
                    <div className="flex items-center space-x-2">
                      <input
                        type="checkbox"
                        checked={isChecked}
                        onChange={() => toggleHost(host.id)}
                        className="rounded border-gray-300 text-blue-600 focus:ring-blue-500"
                      />
                      <span className="font-semibold text-gray-900 dark:text-white">{host.name}</span>
                      {host.region && (
                        <span className="text-[10px] text-gray-400 dark:text-gray-500">
                          ({host.region})
                        </span>
                      )}
                    </div>
                    <span className="text-[10px] font-mono text-gray-400">
                      {host.endpoint_url}
                    </span>
                  </label>
                );
              })}
            </div>
          </div>

          {/* Enabled Status Toggle */}
          <div className="flex items-center justify-between p-3 bg-gray-50 dark:bg-gray-800/40 border border-gray-200 dark:border-gray-700/60 rounded-xl">
            <div>
              <div className="text-xs font-semibold text-gray-900 dark:text-white">Active in Catalog</div>
              <div className="text-[11px] text-gray-500 dark:text-gray-400">
                When enabled, clients can choose this image for provisioning.
              </div>
            </div>
            <button
              type="button"
              onClick={() => setEnabled((prev) => !prev)}
              className={`w-11 h-6 flex items-center rounded-full p-1 transition duration-200 ease-in-out ${
                enabled ? "bg-blue-600 justify-end" : "bg-gray-300 dark:bg-gray-700 justify-start"
              }`}
            >
              <div className="bg-white w-4 h-4 rounded-full shadow-md" />
            </button>
          </div>

          {/* Modal Footer Actions */}
          <div className="flex items-center justify-end space-x-3 pt-3 border-t border-gray-200 dark:border-gray-800">
            <button
              type="button"
              onClick={onClose}
              disabled={loading}
              className="px-4 py-2.5 text-xs font-semibold text-gray-600 dark:text-gray-400 hover:text-gray-900 dark:hover:text-white transition"
            >
              Cancel
            </button>
            <button
              type="submit"
              disabled={loading}
              className="px-5 py-2.5 bg-blue-600 hover:bg-blue-700 text-white rounded-xl text-xs font-semibold shadow-md shadow-blue-600/20 transition disabled:opacity-50"
            >
              {loading ? "Saving..." : isEditing ? "Update Image" : "Add Image"}
            </button>
          </div>
        </form>
      </div>
    </div>
  );
}
