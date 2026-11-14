"use client";

import React, { useCallback, useEffect, useState } from "react";
import {
  X,
  Layers,
  Server,
  Plus,
  Edit2,
  Trash2,
  Search,
  AlertTriangle,
  Loader2,
  CheckCircle2,
  XCircle,
} from "lucide-react";
import { api, HostNode, ImageItem } from "@/lib/api";
import { extractPaginationMeta } from "@/lib/api/client";
import { CreateImageModal } from "./CreateImageModal";
import { ConfirmModal } from "@/components/ui/ConfirmModal";
import { PaginationBar } from "@/components/ui/PaginationBar";
import { useToast } from "@/lib/toast/ToastContext";
import { useModalEscape } from "@/lib/hooks/useModalEscape";

interface ImagesModalProps {
  onClose: () => void;
  hosts: HostNode[];
}

export function ImagesModal({ onClose, hosts }: ImagesModalProps) {
  const { toast } = useToast();

  const [images, setImages] = useState<ImageItem[]>([]);
  const [loading, setLoading] = useState(true);
  const [search, setSearch] = useState("");
  const [debouncedSearch, setDebouncedSearch] = useState("");
  const [osTypeFilter, setOsTypeFilter] = useState<string>("all");
  const [page, setPage] = useState(1);
  const [perPage, setPerPage] = useState(10);
  const [lastPage, setLastPage] = useState(1);
  const [total, setTotal] = useState(0);
  const [editingImage, setEditingImage] = useState<ImageItem | null>(null);
  const [isCreateOpen, setIsCreateOpen] = useState(false);
  const [togglingId, setTogglingId] = useState<number | null>(null);
  const [deleteTarget, setDeleteTarget] = useState<ImageItem | null>(null);
  const [deleting, setDeleting] = useState(false);

  useModalEscape(onClose, !deleting && togglingId === null);

  useEffect(() => {
    const timer = setTimeout(() => {
      setDebouncedSearch((prev) => {
        if (prev !== search) {
          setPage(1);
        }
        return search;
      });
    }, 300);
    return () => clearTimeout(timer);
  }, [search]);

  const fetchImagesList = useCallback(async () => {
    try {
      setLoading(true);
      const res = await api.adminGetImages({
        search: debouncedSearch.trim() || undefined,
        os_type: osTypeFilter === "all" ? undefined : osTypeFilter,
        page,
        per_page: perPage,
      });
      setImages(res.data);
      const meta = extractPaginationMeta(res, perPage);
      setLastPage(meta.lastPage);
      setTotal(meta.total);
      if (page > meta.lastPage && meta.lastPage >= 1) {
        setPage(meta.lastPage);
      }
    } catch (err) {
      console.error("Failed to load images", err);
      toast.error(err instanceof Error ? err.message : "Failed to load images");
    } finally {
      setLoading(false);
    }
  }, [debouncedSearch, osTypeFilter, page, perPage, toast]);

  useEffect(() => {
    let ignore = false;
    queueMicrotask(() => {
      if (!ignore) {
        void fetchImagesList();
      }
    });
    return () => {
      ignore = true;
    };
  }, [fetchImagesList]);

  const handleToggleEnabled = async (image: ImageItem) => {
    try {
      setTogglingId(image.id);
      const updated = await api.adminUpdateImage(image.id, {
        enabled: !image.enabled,
      });
      setImages((prev) =>
        prev.map((img) => (img.id === image.id ? { ...img, enabled: updated.image.enabled } : img))
      );
    } catch (err) {
      console.error("Failed to toggle status", err);
      toast.error(err instanceof Error ? err.message : "Failed to toggle image status");
    } finally {
      setTogglingId(null);
    }
  };

  const handleUnlinkHost = async (image: ImageItem, hostIdToRemove: number) => {
    const currentHostIds = image.host_ids ?? image.hosts?.map((h) => h.id) ?? [];
    const newHostIds = currentHostIds.filter((id) => id !== hostIdToRemove);

    try {
      const updated = await api.adminUpdateImage(image.id, {
        host_ids: newHostIds,
      });
      setImages((prev) =>
        prev.map((img) => (img.id === image.id ? updated.image : img))
      );
    } catch (err) {
      console.error("Failed to remove compute node from image", err);
      toast.error(err instanceof Error ? err.message : "Failed to remove compute node from image");
    }
  };

  const handleConfirmDeleteImage = async () => {
    if (!deleteTarget) return;

    try {
      setDeleting(true);
      await api.adminDeleteImage(deleteTarget.id);
      toast.success(`Image '${deleteTarget.name}' removed from allowlist catalog.`);
      setDeleteTarget(null);
      await fetchImagesList();
    } catch (err) {
      console.error("Failed to delete image", err);
      toast.error(err instanceof Error ? err.message : "Failed to delete image");
    } finally {
      setDeleting(false);
    }
  };

  return (
    <div className="fixed inset-0 z-50 flex items-center justify-center p-4 bg-black/60 backdrop-blur-sm animate-in fade-in duration-200">
      <div className="bg-white dark:bg-gray-900 border border-gray-200 dark:border-gray-800 rounded-3xl w-full max-w-4xl overflow-hidden shadow-2xl relative transition-colors flex flex-col max-h-[85vh]">
        {/* Header */}
        <div className="flex items-center justify-between px-6 py-4 border-b border-gray-200 dark:border-gray-800">
          <div className="flex items-center space-x-3">
            <div className="p-2.5 bg-blue-500/10 dark:bg-blue-500/20 text-blue-600 dark:text-blue-400 rounded-2xl">
              <Layers className="w-5 h-5" />
            </div>
            <div>
              <h2 className="text-base font-bold text-gray-900 dark:text-white flex items-center space-x-2">
                <span>OS Images Catalog & Node Allowlist</span>
                <span className="px-2 py-0.5 rounded-full text-[10px] font-mono bg-blue-500/10 text-blue-600 dark:text-blue-400">
                  {total > 0 ? total : images.length}
                </span>
              </h2>
              <p className="text-xs text-gray-500 dark:text-gray-400">
                Manage approved operating system images and their compute node assignments
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

        {/* Toolbar */}
        <div className="p-4 border-b border-gray-200 dark:border-gray-800 bg-gray-50/50 dark:bg-gray-800/30 flex flex-col sm:flex-row items-center justify-between gap-3">
          <div className="flex items-center space-x-2 w-full sm:w-auto">
            {/* Search */}
            <div className="relative w-full sm:w-64">
              <Search className="w-3.5 h-3.5 absolute left-3 top-1/2 -translate-y-1/2 text-gray-400" />
              <input
                type="text"
                placeholder="Search images..."
                value={search}
                onChange={(e) => setSearch(e.target.value)}
                className="w-full pl-9 pr-3 py-1.5 bg-white dark:bg-gray-900 border border-gray-200 dark:border-gray-700 rounded-xl text-xs text-gray-900 dark:text-white placeholder-gray-400 focus:outline-none focus:ring-2 focus:ring-blue-500"
              />
            </div>

            {/* OS Family Filter */}
            <select
              value={osTypeFilter}
              onChange={(e) => {
                setOsTypeFilter(e.target.value);
                setPage(1);
              }}
              className="px-3 py-1.5 bg-white dark:bg-gray-900 border border-gray-200 dark:border-gray-700 rounded-xl text-xs text-gray-900 dark:text-white focus:outline-none focus:ring-2 focus:ring-blue-500"
            >
              <option value="all">All OS Types</option>
              <option value="linux">Linux (VM & Containers)</option>
              <option value="windows">Windows (VM Only)</option>
              <option value="bsd">BSD</option>
              <option value="other">Other</option>
            </select>
          </div>

          <button
            onClick={() => {
              setEditingImage(null);
              setIsCreateOpen(true);
            }}
            className="w-full sm:w-auto flex items-center justify-center space-x-1.5 px-3.5 py-1.5 bg-blue-600 hover:bg-blue-700 text-white rounded-xl text-xs font-semibold shadow-md shadow-blue-600/20 transition"
          >
            <Plus className="w-4 h-4" />
            <span>Add Approved Image</span>
          </button>
        </div>

        {/* Table Content */}
        <div className="flex-1 overflow-y-auto p-4">
          {loading ? (
            <div className="flex flex-col items-center justify-center py-12 text-gray-400 space-y-2">
              <Loader2 className="w-6 h-6 animate-spin text-blue-500" />
              <span className="text-xs">Loading image catalog...</span>
            </div>
          ) : images.length === 0 ? (
            <div className="text-center py-12 text-gray-400">
              <Layers className="w-8 h-8 mx-auto mb-2 opacity-40" />
              <p className="text-xs">No approved images found matching criteria.</p>
            </div>
          ) : (
            <div className="space-y-3">
              {images.map((img) => {
                const assignedHosts = hosts.filter((h) =>
                  (img.host_ids ?? img.hosts?.map((host) => host.id) ?? []).includes(h.id)
                );

                return (
                  <div
                    key={img.id}
                    className={`p-4 rounded-2xl border transition ${
                      img.enabled
                        ? "bg-white dark:bg-gray-800/60 border-gray-200 dark:border-gray-700/80"
                        : "bg-gray-50/70 dark:bg-gray-900/40 border-gray-200/60 dark:border-gray-800 opacity-70"
                    }`}
                  >
                    <div className="flex flex-col md:flex-row md:items-center justify-between gap-3">
                      {/* Left: Name, Type, Source */}
                      <div className="space-y-1">
                        <div className="flex items-center space-x-2">
                          <span className="font-mono text-xs font-bold text-gray-900 dark:text-white">
                            {img.name}
                          </span>

                          {/* OS Type Badge */}
                          <span
                            className={`px-2 py-0.5 rounded-md text-[10px] font-bold uppercase tracking-wider ${
                              img.os_type === "linux"
                                ? "bg-emerald-500/10 text-emerald-600 dark:text-emerald-400 border border-emerald-500/20"
                                : "bg-sky-500/10 text-sky-600 dark:text-sky-400 border border-sky-500/20"
                            }`}
                          >
                            {img.os_type}
                          </span>

                          {/* Compatibility Badge */}
                          <span className="text-[10px] text-gray-400">
                            {img.os_type === "linux" ? "KVM & LXC" : "KVM Only"}
                          </span>
                        </div>

                        <div className="text-[11px] text-gray-500 dark:text-gray-400 font-mono flex items-center space-x-1.5">
                          <span>{img.source}</span>
                          <span>·</span>
                          <span className="text-gray-400">{img.protocol}</span>
                        </div>
                      </div>

                      {/* Right: Actions */}
                      <div className="flex items-center space-x-2">
                        {/* Status Switch */}
                        <button
                          onClick={() => handleToggleEnabled(img)}
                          disabled={togglingId === img.id}
                          className={`flex items-center space-x-1.5 px-2.5 py-1 rounded-xl text-xs font-semibold border transition ${
                            img.enabled
                              ? "bg-emerald-500/10 border-emerald-500/30 text-emerald-600 dark:text-emerald-400"
                              : "bg-gray-100 dark:bg-gray-800 border-gray-300 dark:border-gray-700 text-gray-500"
                          }`}
                          title="Toggle enabled/disabled status in client catalog"
                        >
                          {img.enabled ? (
                            <>
                              <CheckCircle2 className="w-3.5 h-3.5" />
                              <span>Active</span>
                            </>
                          ) : (
                            <>
                              <XCircle className="w-3.5 h-3.5" />
                              <span>Disabled</span>
                            </>
                          )}
                        </button>

                        <button
                          onClick={() => {
                            setEditingImage(img);
                            setIsCreateOpen(true);
                          }}
                          className="p-1.5 text-gray-500 hover:text-blue-600 dark:hover:text-blue-400 hover:bg-gray-100 dark:hover:bg-gray-800 rounded-lg transition"
                          title="Edit Image"
                        >
                          <Edit2 className="w-4 h-4" />
                        </button>

                        <button
                          onClick={() => setDeleteTarget(img)}
                          className="p-1.5 text-gray-500 hover:text-red-600 dark:hover:text-red-400 hover:bg-red-50 dark:hover:bg-red-500/10 rounded-lg transition"
                          title="Remove from allowlist"
                        >
                          <Trash2 className="w-4 h-4" />
                        </button>
                      </div>
                    </div>

                    {/* Associated Compute Nodes (with X button) */}
                    <div className="mt-3 pt-2.5 border-t border-gray-100 dark:border-gray-800/80">
                      <div className="text-[11px] font-semibold text-gray-500 dark:text-gray-400 mb-1.5 flex items-center space-x-1">
                        <Server className="w-3 h-3 text-blue-500" />
                        <span>Available on Compute Nodes:</span>
                      </div>

                      <div className="flex flex-wrap items-center gap-1.5">
                        {assignedHosts.length > 0 ? (
                          assignedHosts.map((host) => (
                            <span
                              key={host.id}
                              className="inline-flex items-center space-x-1 px-2 py-0.5 bg-gray-100 dark:bg-gray-800 text-gray-700 dark:text-gray-300 border border-gray-200 dark:border-gray-700 rounded-md text-[11px]"
                            >
                              <span>{host.name}</span>
                              <button
                                type="button"
                                onClick={() => handleUnlinkHost(img, host.id)}
                                className="text-gray-400 hover:text-red-500 rounded p-0.5 ml-0.5 transition"
                                title={`Unlink from ${host.name}`}
                              >
                                <X className="w-2.5 h-2.5" />
                              </button>
                            </span>
                          ))
                        ) : (
                          <span className="text-[11px] text-red-500 italic flex items-center space-x-1">
                            <AlertTriangle className="w-3 h-3 inline" />
                            <span>Not assigned to any compute node (unavailable for provisioning).</span>
                          </span>
                        )}
                      </div>
                    </div>
                  </div>
                );
              })}
            </div>
          )}
        </div>

        {/* Pagination Bar */}
        {total > 0 && (
          <PaginationBar
            currentPage={page}
            lastPage={lastPage}
            perPage={perPage}
            total={total}
            onPageChange={setPage}
            onPerPageChange={(newPerPage) => {
              setPerPage(newPerPage);
              setPage(1);
            }}
          />
        )}
      </div>

      {/* Add / Edit Image Modal */}
      {isCreateOpen && (
        <CreateImageModal
          image={editingImage}
          hosts={hosts}
          onClose={() => setIsCreateOpen(false)}
          onSuccess={() => {
            fetchImagesList();
          }}
        />
      )}

      {/* Delete Image Confirmation Modal */}
      <ConfirmModal
        isOpen={!!deleteTarget}
        title="Remove Approved Image"
        message={
          deleteTarget
            ? `Are you sure you want to remove '${deleteTarget.name}' from the allowlist catalog?`
            : ""
        }
        variant="danger"
        isLoading={deleting}
        onConfirm={handleConfirmDeleteImage}
        onClose={() => {
          if (!deleting) {
            setDeleteTarget(null);
          }
        }}
      />
    </div>
  );
}
