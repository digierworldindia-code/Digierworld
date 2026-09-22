-- CreateEnum
CREATE TYPE "user_status" AS ENUM ('PENDING_ACTIVATION', 'ACTIVE', 'SUSPENDED', 'DISABLED');

-- CreateEnum
CREATE TYPE "role_key" AS ENUM ('SUPER_ADMIN', 'ADMIN', 'WAREHOUSE', 'WARRANTY_MANAGER', 'SALES_MANAGER', 'DEALER', 'REPORTING');

-- CreateEnum
CREATE TYPE "dealer_status" AS ENUM ('PENDING', 'ACTIVE', 'SUSPENDED', 'TERMINATED');

-- CreateEnum
CREATE TYPE "mattress_status" AS ENUM ('MANUFACTURED', 'IN_DISPATCH', 'DISPATCHED', 'DEALER_RECEIVED', 'SOLD', 'CLAIM_OPEN', 'REPLACED', 'RETURNED', 'SCRAPPED');

-- CreateEnum
CREATE TYPE "dispatch_status" AS ENUM ('DRAFT', 'DISPATCHED', 'PARTIALLY_RECEIVED', 'RECEIVED', 'CANCELLED');

-- CreateEnum
CREATE TYPE "receipt_condition" AS ENUM ('OK', 'DAMAGED', 'MISSING');

-- CreateEnum
CREATE TYPE "warranty_status" AS ENUM ('ACTIVE', 'EXPIRED', 'VOID', 'SUPERSEDED');

-- CreateEnum
CREATE TYPE "claim_status" AS ENUM ('SUBMITTED', 'UNDER_REVIEW', 'INFO_REQUESTED', 'APPROVED', 'REJECTED', 'REPLACED', 'CLOSED', 'WITHDRAWN');

-- CreateEnum
CREATE TYPE "risk_level" AS ENUM ('LOW', 'MEDIUM', 'HIGH');

-- CreateEnum
CREATE TYPE "claim_issue_category" AS ENUM ('SAGGING', 'FABRIC_TEAR', 'FOAM_DEGRADATION', 'SPRING_FAILURE', 'STITCHING', 'SIZE_MISMATCH', 'TRANSIT_DAMAGE', 'OTHER');

-- CreateEnum
CREATE TYPE "media_kind" AS ENUM ('CLAIM_PHOTO', 'CLAIM_INVOICE', 'PRODUCT_IMAGE', 'OG_IMAGE');

-- CreateEnum
CREATE TYPE "lead_status" AS ENUM ('NEW', 'CONTACTED', 'FOLLOW_UP', 'CONVERTED', 'CLOSED');

-- CreateEnum
CREATE TYPE "dealer_application_status" AS ENUM ('NEW', 'INFO_REQUESTED', 'APPROVED', 'REJECTED');

-- CreateEnum
CREATE TYPE "publish_status" AS ENUM ('DRAFT', 'PUBLISHED', 'ARCHIVED');

-- CreateEnum
CREATE TYPE "seo_scope" AS ENUM ('GLOBAL', 'PAGE', 'PRODUCT');

-- CreateEnum
CREATE TYPE "notification_channel" AS ENUM ('IN_APP', 'EMAIL', 'WHATSAPP', 'SMS');

-- CreateEnum
CREATE TYPE "backup_kind" AS ENUM ('DAILY', 'WEEKLY', 'MONTHLY', 'MANUAL', 'EXPORT');

-- CreateEnum
CREATE TYPE "backup_status" AS ENUM ('RUNNING', 'SUCCESS', 'FAILED');

-- CreateTable
CREATE TABLE "users" (
    "id" UUID NOT NULL,
    "email" VARCHAR(255) NOT NULL,
    "password_hash" TEXT NOT NULL,
    "full_name" VARCHAR(160) NOT NULL,
    "phone" VARCHAR(20),
    "status" "user_status" NOT NULL DEFAULT 'PENDING_ACTIVATION',
    "mfa_enabled" BOOLEAN NOT NULL DEFAULT false,
    "mfa_secret_encrypted" TEXT,
    "mfa_recovery_codes" JSONB,
    "mfa_enrolled_at" TIMESTAMPTZ(6),
    "must_change_password" BOOLEAN NOT NULL DEFAULT false,
    "password_changed_at" TIMESTAMPTZ(6),
    "failed_login_count" INTEGER NOT NULL DEFAULT 0,
    "locked_until" TIMESTAMPTZ(6),
    "last_login_at" TIMESTAMPTZ(6),
    "last_login_ip" VARCHAR(64),
    "created_at" TIMESTAMPTZ(6) NOT NULL DEFAULT CURRENT_TIMESTAMP,
    "updated_at" TIMESTAMPTZ(6) NOT NULL,
    "created_by" UUID,
    "updated_by" UUID,
    "deleted_at" TIMESTAMPTZ(6),
    "deleted_by" UUID,
    "delete_reason" TEXT,

    CONSTRAINT "users_pkey" PRIMARY KEY ("id")
);

-- CreateTable
CREATE TABLE "roles" (
    "id" UUID NOT NULL,
    "key" "role_key" NOT NULL,
    "name" VARCHAR(80) NOT NULL,
    "description" TEXT,
    "is_system" BOOLEAN NOT NULL DEFAULT true,
    "rank" INTEGER NOT NULL DEFAULT 100,
    "created_at" TIMESTAMPTZ(6) NOT NULL DEFAULT CURRENT_TIMESTAMP,
    "updated_at" TIMESTAMPTZ(6) NOT NULL,

    CONSTRAINT "roles_pkey" PRIMARY KEY ("id")
);

-- CreateTable
CREATE TABLE "permissions" (
    "id" UUID NOT NULL,
    "key" VARCHAR(80) NOT NULL,
    "group" VARCHAR(40) NOT NULL,
    "description" TEXT NOT NULL,
    "created_at" TIMESTAMPTZ(6) NOT NULL DEFAULT CURRENT_TIMESTAMP,

    CONSTRAINT "permissions_pkey" PRIMARY KEY ("id")
);

-- CreateTable
CREATE TABLE "role_permissions" (
    "role_id" UUID NOT NULL,
    "permission_id" UUID NOT NULL,

    CONSTRAINT "role_permissions_pkey" PRIMARY KEY ("role_id","permission_id")
);

-- CreateTable
CREATE TABLE "user_roles" (
    "user_id" UUID NOT NULL,
    "role_id" UUID NOT NULL,
    "assigned_at" TIMESTAMPTZ(6) NOT NULL DEFAULT CURRENT_TIMESTAMP,
    "assigned_by" UUID,

    CONSTRAINT "user_roles_pkey" PRIMARY KEY ("user_id","role_id")
);

-- CreateTable
CREATE TABLE "sessions" (
    "id" UUID NOT NULL,
    "user_id" UUID NOT NULL,
    "token_hash" CHAR(64) NOT NULL,
    "ip" VARCHAR(64),
    "user_agent" VARCHAR(400),
    "mfa_satisfied" BOOLEAN NOT NULL DEFAULT false,
    "created_at" TIMESTAMPTZ(6) NOT NULL DEFAULT CURRENT_TIMESTAMP,
    "last_seen_at" TIMESTAMPTZ(6) NOT NULL DEFAULT CURRENT_TIMESTAMP,
    "absolute_expiry" TIMESTAMPTZ(6) NOT NULL,
    "revoked_at" TIMESTAMPTZ(6),
    "revoked_reason" VARCHAR(120),

    CONSTRAINT "sessions_pkey" PRIMARY KEY ("id")
);

-- CreateTable
CREATE TABLE "refresh_tokens" (
    "id" UUID NOT NULL,
    "session_id" UUID NOT NULL,
    "token_hash" CHAR(64) NOT NULL,
    "issued_at" TIMESTAMPTZ(6) NOT NULL DEFAULT CURRENT_TIMESTAMP,
    "expires_at" TIMESTAMPTZ(6) NOT NULL,
    "used_at" TIMESTAMPTZ(6),
    "revoked_at" TIMESTAMPTZ(6),
    "replaced_by" UUID,

    CONSTRAINT "refresh_tokens_pkey" PRIMARY KEY ("id")
);

-- CreateTable
CREATE TABLE "failed_login_attempts" (
    "id" BIGSERIAL NOT NULL,
    "email" VARCHAR(255) NOT NULL,
    "ip_hash" CHAR(64) NOT NULL,
    "user_agent" VARCHAR(400),
    "reason" VARCHAR(60) NOT NULL,
    "attempted_at" TIMESTAMPTZ(6) NOT NULL DEFAULT CURRENT_TIMESTAMP,

    CONSTRAINT "failed_login_attempts_pkey" PRIMARY KEY ("id")
);

-- CreateTable
CREATE TABLE "password_reset_tokens" (
    "id" UUID NOT NULL,
    "user_id" UUID NOT NULL,
    "token_hash" CHAR(64) NOT NULL,
    "expires_at" TIMESTAMPTZ(6) NOT NULL,
    "used_at" TIMESTAMPTZ(6),
    "created_ip" VARCHAR(64),
    "created_at" TIMESTAMPTZ(6) NOT NULL DEFAULT CURRENT_TIMESTAMP,

    CONSTRAINT "password_reset_tokens_pkey" PRIMARY KEY ("id")
);

-- CreateTable
CREATE TABLE "dealers" (
    "id" UUID NOT NULL,
    "code" VARCHAR(20) NOT NULL,
    "business_name" VARCHAR(180) NOT NULL,
    "owner_name" VARCHAR(160) NOT NULL,
    "email" VARCHAR(255),
    "phone" VARCHAR(20) NOT NULL,
    "gst_number" VARCHAR(20),
    "address_line1" VARCHAR(200) NOT NULL,
    "address_line2" VARCHAR(200),
    "city" VARCHAR(80) NOT NULL,
    "state" VARCHAR(80) NOT NULL,
    "pincode" VARCHAR(10) NOT NULL,
    "latitude" DECIMAL(9,6),
    "longitude" DECIMAL(9,6),
    "status" "dealer_status" NOT NULL DEFAULT 'PENDING',
    "public_listed" BOOLEAN NOT NULL DEFAULT false,
    "is_showroom" BOOLEAN NOT NULL DEFAULT true,
    "onboarded_at" TIMESTAMPTZ(6),
    "notes" TEXT,
    "created_at" TIMESTAMPTZ(6) NOT NULL DEFAULT CURRENT_TIMESTAMP,
    "updated_at" TIMESTAMPTZ(6) NOT NULL,
    "created_by" UUID,
    "updated_by" UUID,
    "deleted_at" TIMESTAMPTZ(6),
    "deleted_by" UUID,
    "delete_reason" TEXT,

    CONSTRAINT "dealers_pkey" PRIMARY KEY ("id")
);

-- CreateTable
CREATE TABLE "dealer_users" (
    "user_id" UUID NOT NULL,
    "dealer_id" UUID NOT NULL,
    "is_primary" BOOLEAN NOT NULL DEFAULT false,
    "created_at" TIMESTAMPTZ(6) NOT NULL DEFAULT CURRENT_TIMESTAMP,

    CONSTRAINT "dealer_users_pkey" PRIMARY KEY ("user_id")
);

-- CreateTable
CREATE TABLE "dealer_applications" (
    "id" UUID NOT NULL,
    "business_name" VARCHAR(180) NOT NULL,
    "owner_name" VARCHAR(160) NOT NULL,
    "mobile" VARCHAR(20) NOT NULL,
    "email" VARCHAR(255) NOT NULL,
    "city" VARCHAR(80) NOT NULL,
    "state" VARCHAR(80) NOT NULL,
    "address" VARCHAR(400) NOT NULL,
    "gst_number" VARCHAR(20),
    "has_existing_business" BOOLEAN NOT NULL DEFAULT false,
    "existing_business_details" TEXT,
    "message" TEXT,
    "status" "dealer_application_status" NOT NULL DEFAULT 'NEW',
    "review_notes" TEXT,
    "reviewed_by" UUID,
    "reviewed_at" TIMESTAMPTZ(6),
    "created_dealer_id" UUID,
    "ip_hash" CHAR(64),
    "user_agent" VARCHAR(400),
    "spam_score" INTEGER NOT NULL DEFAULT 0,
    "created_at" TIMESTAMPTZ(6) NOT NULL DEFAULT CURRENT_TIMESTAMP,
    "updated_at" TIMESTAMPTZ(6) NOT NULL,

    CONSTRAINT "dealer_applications_pkey" PRIMARY KEY ("id")
);

-- CreateTable
CREATE TABLE "warehouses" (
    "id" UUID NOT NULL,
    "code" VARCHAR(20) NOT NULL,
    "name" VARCHAR(160) NOT NULL,
    "address" VARCHAR(300) NOT NULL,
    "city" VARCHAR(80) NOT NULL,
    "state" VARCHAR(80) NOT NULL,
    "pincode" VARCHAR(10) NOT NULL,
    "is_active" BOOLEAN NOT NULL DEFAULT true,
    "created_at" TIMESTAMPTZ(6) NOT NULL DEFAULT CURRENT_TIMESTAMP,
    "updated_at" TIMESTAMPTZ(6) NOT NULL,

    CONSTRAINT "warehouses_pkey" PRIMARY KEY ("id")
);

-- CreateTable
CREATE TABLE "products" (
    "id" UUID NOT NULL,
    "slug" VARCHAR(120) NOT NULL,
    "name" VARCHAR(160) NOT NULL,
    "sku_prefix" VARCHAR(12) NOT NULL,
    "category" VARCHAR(60) NOT NULL,
    "tagline" VARCHAR(200),
    "short_description" VARCHAR(400) NOT NULL,
    "description" TEXT NOT NULL,
    "comfort_level" VARCHAR(40) NOT NULL,
    "firmness_score" INTEGER NOT NULL,
    "materials" JSONB NOT NULL DEFAULT '[]',
    "features" JSONB NOT NULL DEFAULT '[]',
    "specifications" JSONB NOT NULL DEFAULT '{}',
    "care_instructions" TEXT,
    "warranty_years" INTEGER NOT NULL,
    "trial_nights" INTEGER,
    "status" "publish_status" NOT NULL DEFAULT 'DRAFT',
    "is_featured" BOOLEAN NOT NULL DEFAULT false,
    "sort_order" INTEGER NOT NULL DEFAULT 100,
    "published_at" TIMESTAMPTZ(6),
    "created_at" TIMESTAMPTZ(6) NOT NULL DEFAULT CURRENT_TIMESTAMP,
    "updated_at" TIMESTAMPTZ(6) NOT NULL,
    "created_by" UUID,
    "updated_by" UUID,
    "deleted_at" TIMESTAMPTZ(6),
    "deleted_by" UUID,
    "delete_reason" TEXT,

    CONSTRAINT "products_pkey" PRIMARY KEY ("id")
);

-- CreateTable
CREATE TABLE "product_variants" (
    "id" UUID NOT NULL,
    "product_id" UUID NOT NULL,
    "sku" VARCHAR(40) NOT NULL,
    "size_label" VARCHAR(60) NOT NULL,
    "width_in" INTEGER NOT NULL,
    "length_in" INTEGER NOT NULL,
    "height_in" INTEGER NOT NULL,
    "mrp" DECIMAL(12,2) NOT NULL,
    "weight_kg" DECIMAL(6,2),
    "status" "publish_status" NOT NULL DEFAULT 'PUBLISHED',
    "sort_order" INTEGER NOT NULL DEFAULT 100,
    "created_at" TIMESTAMPTZ(6) NOT NULL DEFAULT CURRENT_TIMESTAMP,
    "updated_at" TIMESTAMPTZ(6) NOT NULL,
    "deleted_at" TIMESTAMPTZ(6),

    CONSTRAINT "product_variants_pkey" PRIMARY KEY ("id")
);

-- CreateTable
CREATE TABLE "manufacturing_batches" (
    "id" UUID NOT NULL,
    "batch_code" VARCHAR(30) NOT NULL,
    "warehouse_id" UUID NOT NULL,
    "manufactured_on" DATE NOT NULL,
    "planned_quantity" INTEGER NOT NULL,
    "produced_quantity" INTEGER NOT NULL DEFAULT 0,
    "line_supervisor" VARCHAR(120),
    "quality_checked_by" VARCHAR(120),
    "notes" TEXT,
    "created_at" TIMESTAMPTZ(6) NOT NULL DEFAULT CURRENT_TIMESTAMP,
    "updated_at" TIMESTAMPTZ(6) NOT NULL,
    "created_by" UUID,

    CONSTRAINT "manufacturing_batches_pkey" PRIMARY KEY ("id")
);

-- CreateTable
CREATE TABLE "mattresses" (
    "id" UUID NOT NULL,
    "serial_number" VARCHAR(20) NOT NULL,
    "qr_token" VARCHAR(43) NOT NULL,
    "product_variant_id" UUID NOT NULL,
    "batch_id" UUID NOT NULL,
    "current_status" "mattress_status" NOT NULL DEFAULT 'MANUFACTURED',
    "current_dealer_id" UUID,
    "current_warehouse_id" UUID,
    "manufactured_at" TIMESTAMPTZ(6) NOT NULL,
    "dispatched_at" TIMESTAMPTZ(6),
    "received_at" TIMESTAMPTZ(6),
    "sold_at" TIMESTAMPTZ(6),
    "is_replacement" BOOLEAN NOT NULL DEFAULT false,
    "replacement_for_claim_id" UUID,
    "grade_note" VARCHAR(200),
    "created_at" TIMESTAMPTZ(6) NOT NULL DEFAULT CURRENT_TIMESTAMP,
    "updated_at" TIMESTAMPTZ(6) NOT NULL,
    "created_by" UUID,
    "updated_by" UUID,
    "deleted_at" TIMESTAMPTZ(6),
    "deleted_by" UUID,
    "delete_reason" TEXT,

    CONSTRAINT "mattresses_pkey" PRIMARY KEY ("id")
);

-- CreateTable
CREATE TABLE "mattress_events" (
    "id" BIGSERIAL NOT NULL,
    "mattress_id" UUID NOT NULL,
    "event_type" VARCHAR(40) NOT NULL,
    "from_status" "mattress_status",
    "to_status" "mattress_status",
    "actor_user_id" UUID,
    "dealer_id" UUID,
    "payload" JSONB,
    "occurred_at" TIMESTAMPTZ(6) NOT NULL DEFAULT CURRENT_TIMESTAMP,

    CONSTRAINT "mattress_events_pkey" PRIMARY KEY ("id")
);

-- CreateTable
CREATE TABLE "dispatches" (
    "id" UUID NOT NULL,
    "dispatch_code" VARCHAR(30) NOT NULL,
    "warehouse_id" UUID NOT NULL,
    "dealer_id" UUID NOT NULL,
    "status" "dispatch_status" NOT NULL DEFAULT 'DRAFT',
    "dispatched_at" TIMESTAMPTZ(6),
    "expected_at" TIMESTAMPTZ(6),
    "transporter" VARCHAR(120),
    "lr_number" VARCHAR(60),
    "vehicle_number" VARCHAR(30),
    "remarks" TEXT,
    "created_at" TIMESTAMPTZ(6) NOT NULL DEFAULT CURRENT_TIMESTAMP,
    "updated_at" TIMESTAMPTZ(6) NOT NULL,
    "created_by" UUID,
    "updated_by" UUID,
    "deleted_at" TIMESTAMPTZ(6),
    "deleted_by" UUID,
    "delete_reason" TEXT,

    CONSTRAINT "dispatches_pkey" PRIMARY KEY ("id")
);

-- CreateTable
CREATE TABLE "dispatch_items" (
    "id" UUID NOT NULL,
    "dispatch_id" UUID NOT NULL,
    "mattress_id" UUID NOT NULL,
    "added_at" TIMESTAMPTZ(6) NOT NULL DEFAULT CURRENT_TIMESTAMP,

    CONSTRAINT "dispatch_items_pkey" PRIMARY KEY ("id")
);

-- CreateTable
CREATE TABLE "dealer_receipts" (
    "id" UUID NOT NULL,
    "dispatch_id" UUID NOT NULL,
    "dealer_id" UUID NOT NULL,
    "received_by_user_id" UUID NOT NULL,
    "received_at" TIMESTAMPTZ(6) NOT NULL DEFAULT CURRENT_TIMESTAMP,
    "remarks" TEXT,
    "created_at" TIMESTAMPTZ(6) NOT NULL DEFAULT CURRENT_TIMESTAMP,

    CONSTRAINT "dealer_receipts_pkey" PRIMARY KEY ("id")
);

-- CreateTable
CREATE TABLE "dealer_receipt_items" (
    "id" UUID NOT NULL,
    "receipt_id" UUID NOT NULL,
    "mattress_id" UUID NOT NULL,
    "condition" "receipt_condition" NOT NULL DEFAULT 'OK',
    "remarks" VARCHAR(300),

    CONSTRAINT "dealer_receipt_items_pkey" PRIMARY KEY ("id")
);

-- CreateTable
CREATE TABLE "customers" (
    "id" UUID NOT NULL,
    "dealer_id" UUID NOT NULL,
    "full_name" VARCHAR(160) NOT NULL,
    "phone_encrypted" TEXT NOT NULL,
    "phone_hash" CHAR(64) NOT NULL,
    "email_encrypted" TEXT,
    "email_hash" CHAR(64),
    "address_line" VARCHAR(300),
    "address_hash" CHAR(64),
    "city" VARCHAR(80),
    "state" VARCHAR(80),
    "pincode" VARCHAR(10),
    "created_at" TIMESTAMPTZ(6) NOT NULL DEFAULT CURRENT_TIMESTAMP,
    "updated_at" TIMESTAMPTZ(6) NOT NULL,
    "created_by" UUID,
    "updated_by" UUID,
    "deleted_at" TIMESTAMPTZ(6),
    "deleted_by" UUID,
    "delete_reason" TEXT,

    CONSTRAINT "customers_pkey" PRIMARY KEY ("id")
);

-- CreateTable
CREATE TABLE "sales" (
    "id" UUID NOT NULL,
    "dealer_id" UUID NOT NULL,
    "mattress_id" UUID NOT NULL,
    "customer_id" UUID NOT NULL,
    "invoice_number" VARCHAR(60) NOT NULL,
    "sold_at" TIMESTAMPTZ(6) NOT NULL,
    "sale_price" DECIMAL(12,2) NOT NULL,
    "payment_mode" VARCHAR(30) NOT NULL,
    "sold_by_user_id" UUID NOT NULL,
    "remarks" TEXT,
    "created_at" TIMESTAMPTZ(6) NOT NULL DEFAULT CURRENT_TIMESTAMP,
    "updated_at" TIMESTAMPTZ(6) NOT NULL,
    "created_by" UUID,
    "updated_by" UUID,
    "deleted_at" TIMESTAMPTZ(6),
    "deleted_by" UUID,
    "delete_reason" TEXT,

    CONSTRAINT "sales_pkey" PRIMARY KEY ("id")
);

-- CreateTable
CREATE TABLE "warranties" (
    "id" UUID NOT NULL,
    "mattress_id" UUID NOT NULL,
    "sale_id" UUID NOT NULL,
    "dealer_id" UUID NOT NULL,
    "customer_id" UUID NOT NULL,
    "start_date" DATE NOT NULL,
    "end_date" DATE NOT NULL,
    "years" INTEGER NOT NULL,
    "status" "warranty_status" NOT NULL DEFAULT 'ACTIVE',
    "terms_version" VARCHAR(20) NOT NULL,
    "void_reason" TEXT,
    "created_at" TIMESTAMPTZ(6) NOT NULL DEFAULT CURRENT_TIMESTAMP,
    "updated_at" TIMESTAMPTZ(6) NOT NULL,
    "created_by" UUID,
    "updated_by" UUID,
    "deleted_at" TIMESTAMPTZ(6),
    "deleted_by" UUID,
    "delete_reason" TEXT,

    CONSTRAINT "warranties_pkey" PRIMARY KEY ("id")
);

-- CreateTable
CREATE TABLE "warranty_claims" (
    "id" UUID NOT NULL,
    "claim_number" VARCHAR(30) NOT NULL,
    "mattress_id" UUID NOT NULL,
    "warranty_id" UUID NOT NULL,
    "dealer_id" UUID NOT NULL,
    "customer_id" UUID NOT NULL,
    "issue_category" "claim_issue_category" NOT NULL,
    "reported_issue" VARCHAR(200) NOT NULL,
    "description" TEXT NOT NULL,
    "status" "claim_status" NOT NULL DEFAULT 'SUBMITTED',
    "risk_level" "risk_level" NOT NULL DEFAULT 'LOW',
    "risk_score" INTEGER NOT NULL DEFAULT 0,
    "risk_signals" JSONB NOT NULL DEFAULT '[]',
    "risk_computed_at" TIMESTAMPTZ(6),
    "submitted_by_user_id" UUID NOT NULL,
    "submitted_at" TIMESTAMPTZ(6) NOT NULL DEFAULT CURRENT_TIMESTAMP,
    "reviewed_by_user_id" UUID,
    "decision_at" TIMESTAMPTZ(6),
    "decision_reason" TEXT,
    "resolution" TEXT,
    "closed_at" TIMESTAMPTZ(6),
    "created_at" TIMESTAMPTZ(6) NOT NULL DEFAULT CURRENT_TIMESTAMP,
    "updated_at" TIMESTAMPTZ(6) NOT NULL,
    "deleted_at" TIMESTAMPTZ(6),
    "deleted_by" UUID,
    "delete_reason" TEXT,

    CONSTRAINT "warranty_claims_pkey" PRIMARY KEY ("id")
);

-- CreateTable
CREATE TABLE "claim_media" (
    "id" UUID NOT NULL,
    "claim_id" UUID NOT NULL,
    "dealer_id" UUID NOT NULL,
    "kind" "media_kind" NOT NULL DEFAULT 'CLAIM_PHOTO',
    "storage_key" VARCHAR(300) NOT NULL,
    "mime_type" VARCHAR(80) NOT NULL,
    "byte_size" INTEGER NOT NULL,
    "width" INTEGER,
    "height" INTEGER,
    "sha256" CHAR(64) NOT NULL,
    "caption" VARCHAR(200),
    "uploaded_by_user_id" UUID NOT NULL,
    "created_at" TIMESTAMPTZ(6) NOT NULL DEFAULT CURRENT_TIMESTAMP,

    CONSTRAINT "claim_media_pkey" PRIMARY KEY ("id")
);

-- CreateTable
CREATE TABLE "claim_events" (
    "id" BIGSERIAL NOT NULL,
    "claim_id" UUID NOT NULL,
    "event_type" VARCHAR(40) NOT NULL,
    "from_status" "claim_status",
    "to_status" "claim_status",
    "actor_user_id" UUID,
    "note" TEXT,
    "is_internal" BOOLEAN NOT NULL DEFAULT false,
    "created_at" TIMESTAMPTZ(6) NOT NULL DEFAULT CURRENT_TIMESTAMP,

    CONSTRAINT "claim_events_pkey" PRIMARY KEY ("id")
);

-- CreateTable
CREATE TABLE "replacements" (
    "id" UUID NOT NULL,
    "claim_id" UUID NOT NULL,
    "original_mattress_id" UUID NOT NULL,
    "replacement_mattress_id" UUID NOT NULL,
    "dealer_id" UUID NOT NULL,
    "approved_by_user_id" UUID NOT NULL,
    "issued_at" TIMESTAMPTZ(6) NOT NULL DEFAULT CURRENT_TIMESTAMP,
    "dispatch_id" UUID,
    "remarks" TEXT,
    "created_at" TIMESTAMPTZ(6) NOT NULL DEFAULT CURRENT_TIMESTAMP,

    CONSTRAINT "replacements_pkey" PRIMARY KEY ("id")
);

-- CreateTable
CREATE TABLE "notifications" (
    "id" UUID NOT NULL,
    "user_id" UUID,
    "dealer_id" UUID,
    "channel" "notification_channel" NOT NULL DEFAULT 'IN_APP',
    "type" VARCHAR(60) NOT NULL,
    "title" VARCHAR(200) NOT NULL,
    "body" TEXT NOT NULL,
    "entity" VARCHAR(60),
    "entity_id" VARCHAR(64),
    "read_at" TIMESTAMPTZ(6),
    "sent_at" TIMESTAMPTZ(6),
    "error" TEXT,
    "created_at" TIMESTAMPTZ(6) NOT NULL DEFAULT CURRENT_TIMESTAMP,

    CONSTRAINT "notifications_pkey" PRIMARY KEY ("id")
);

-- CreateTable
CREATE TABLE "audit_logs" (
    "id" BIGSERIAL NOT NULL,
    "occurred_at" TIMESTAMPTZ(6) NOT NULL DEFAULT CURRENT_TIMESTAMP,
    "user_id" UUID,
    "user_email" VARCHAR(255),
    "role_key" VARCHAR(40),
    "dealer_id" UUID,
    "action" VARCHAR(60) NOT NULL,
    "entity" VARCHAR(60) NOT NULL,
    "entity_id" VARCHAR(64),
    "ip" VARCHAR(64),
    "user_agent" VARCHAR(400),
    "previous_value" JSONB,
    "new_value" JSONB,
    "reason" TEXT,
    "request_id" VARCHAR(64),
    "prev_hash" CHAR(64),
    "row_hash" CHAR(64) NOT NULL DEFAULT '',

    CONSTRAINT "audit_logs_pkey" PRIMARY KEY ("id")
);

-- CreateTable
CREATE TABLE "record_versions" (
    "id" BIGSERIAL NOT NULL,
    "entity" VARCHAR(60) NOT NULL,
    "entity_id" VARCHAR(64) NOT NULL,
    "version" INTEGER NOT NULL,
    "data" JSONB NOT NULL,
    "changed_by" UUID,
    "changed_at" TIMESTAMPTZ(6) NOT NULL DEFAULT CURRENT_TIMESTAMP,
    "reason" TEXT,

    CONSTRAINT "record_versions_pkey" PRIMARY KEY ("id")
);

-- CreateTable
CREATE TABLE "website_pages" (
    "id" UUID NOT NULL,
    "slug" VARCHAR(120) NOT NULL,
    "title" VARCHAR(200) NOT NULL,
    "status" "publish_status" NOT NULL DEFAULT 'DRAFT',
    "hero" JSONB NOT NULL DEFAULT '{}',
    "sections" JSONB NOT NULL DEFAULT '[]',
    "published_at" TIMESTAMPTZ(6),
    "created_at" TIMESTAMPTZ(6) NOT NULL DEFAULT CURRENT_TIMESTAMP,
    "updated_at" TIMESTAMPTZ(6) NOT NULL,
    "created_by" UUID,
    "updated_by" UUID,

    CONSTRAINT "website_pages_pkey" PRIMARY KEY ("id")
);

-- CreateTable
CREATE TABLE "website_products" (
    "id" UUID NOT NULL,
    "product_id" UUID NOT NULL,
    "headline" VARCHAR(200) NOT NULL,
    "subheadline" VARCHAR(300),
    "body_html" TEXT,
    "gallery" JSONB NOT NULL DEFAULT '[]',
    "highlights" JSONB NOT NULL DEFAULT '[]',
    "is_published" BOOLEAN NOT NULL DEFAULT false,
    "sort_order" INTEGER NOT NULL DEFAULT 100,
    "created_at" TIMESTAMPTZ(6) NOT NULL DEFAULT CURRENT_TIMESTAMP,
    "updated_at" TIMESTAMPTZ(6) NOT NULL,
    "updated_by" UUID,

    CONSTRAINT "website_products_pkey" PRIMARY KEY ("id")
);

-- CreateTable
CREATE TABLE "seo_metadata" (
    "id" UUID NOT NULL,
    "scope" "seo_scope" NOT NULL,
    "entity_key" VARCHAR(160),
    "title" VARCHAR(200) NOT NULL,
    "description" VARCHAR(320) NOT NULL,
    "canonical_path" VARCHAR(300),
    "og_title" VARCHAR(200),
    "og_description" VARCHAR(320),
    "og_image_url" VARCHAR(400),
    "twitter_card" VARCHAR(40) NOT NULL DEFAULT 'summary_large_image',
    "robots_index" BOOLEAN NOT NULL DEFAULT true,
    "robots_follow" BOOLEAN NOT NULL DEFAULT true,
    "keywords" TEXT[] DEFAULT ARRAY[]::TEXT[],
    "sitemap_include" BOOLEAN NOT NULL DEFAULT true,
    "sitemap_priority" DECIMAL(2,1) NOT NULL DEFAULT 0.5,
    "sitemap_changefreq" VARCHAR(20) NOT NULL DEFAULT 'monthly',
    "created_at" TIMESTAMPTZ(6) NOT NULL DEFAULT CURRENT_TIMESTAMP,
    "updated_at" TIMESTAMPTZ(6) NOT NULL,
    "updated_by" UUID,

    CONSTRAINT "seo_metadata_pkey" PRIMARY KEY ("id")
);

-- CreateTable
CREATE TABLE "faqs" (
    "id" UUID NOT NULL,
    "question" VARCHAR(300) NOT NULL,
    "answer" TEXT NOT NULL,
    "category" VARCHAR(60) NOT NULL,
    "sort_order" INTEGER NOT NULL DEFAULT 100,
    "is_published" BOOLEAN NOT NULL DEFAULT true,
    "product_id" UUID,
    "page_id" UUID,
    "created_at" TIMESTAMPTZ(6) NOT NULL DEFAULT CURRENT_TIMESTAMP,
    "updated_at" TIMESTAMPTZ(6) NOT NULL,
    "updated_by" UUID,

    CONSTRAINT "faqs_pkey" PRIMARY KEY ("id")
);

-- CreateTable
CREATE TABLE "contact_submissions" (
    "id" UUID NOT NULL,
    "name" VARCHAR(160) NOT NULL,
    "phone" VARCHAR(20) NOT NULL,
    "email" VARCHAR(255),
    "city" VARCHAR(80),
    "requirement" VARCHAR(60) NOT NULL,
    "message" TEXT NOT NULL,
    "status" "lead_status" NOT NULL DEFAULT 'NEW',
    "source" VARCHAR(60) NOT NULL DEFAULT 'website',
    "ip_hash" CHAR(64),
    "user_agent" VARCHAR(400),
    "spam_score" INTEGER NOT NULL DEFAULT 0,
    "assigned_to_user_id" UUID,
    "internal_notes" TEXT,
    "responded_at" TIMESTAMPTZ(6),
    "created_at" TIMESTAMPTZ(6) NOT NULL DEFAULT CURRENT_TIMESTAMP,
    "updated_at" TIMESTAMPTZ(6) NOT NULL,

    CONSTRAINT "contact_submissions_pkey" PRIMARY KEY ("id")
);

-- CreateTable
CREATE TABLE "system_settings" (
    "key" VARCHAR(80) NOT NULL,
    "value" JSONB NOT NULL,
    "description" TEXT,
    "category" VARCHAR(40) NOT NULL DEFAULT 'general',
    "is_secret" BOOLEAN NOT NULL DEFAULT false,
    "updated_at" TIMESTAMPTZ(6) NOT NULL,
    "updated_by" UUID,

    CONSTRAINT "system_settings_pkey" PRIMARY KEY ("key")
);

-- CreateTable
CREATE TABLE "backup_runs" (
    "id" UUID NOT NULL,
    "kind" "backup_kind" NOT NULL,
    "status" "backup_status" NOT NULL DEFAULT 'RUNNING',
    "started_at" TIMESTAMPTZ(6) NOT NULL DEFAULT CURRENT_TIMESTAMP,
    "finished_at" TIMESTAMPTZ(6),
    "artifact_path" VARCHAR(400),
    "byte_size" BIGINT,
    "checksum" CHAR(64),
    "encrypted" BOOLEAN NOT NULL DEFAULT true,
    "offsite_copied" BOOLEAN NOT NULL DEFAULT false,
    "requested_by" UUID,
    "error" TEXT,

    CONSTRAINT "backup_runs_pkey" PRIMARY KEY ("id")
);

-- CreateIndex
CREATE UNIQUE INDEX "users_email_key" ON "users"("email");

-- CreateIndex
CREATE INDEX "users_status_idx" ON "users"("status");

-- CreateIndex
CREATE INDEX "users_deleted_at_idx" ON "users"("deleted_at");

-- CreateIndex
CREATE UNIQUE INDEX "roles_key_key" ON "roles"("key");

-- CreateIndex
CREATE UNIQUE INDEX "permissions_key_key" ON "permissions"("key");

-- CreateIndex
CREATE INDEX "permissions_group_idx" ON "permissions"("group");

-- CreateIndex
CREATE INDEX "user_roles_role_id_idx" ON "user_roles"("role_id");

-- CreateIndex
CREATE UNIQUE INDEX "sessions_token_hash_key" ON "sessions"("token_hash");

-- CreateIndex
CREATE INDEX "sessions_user_id_idx" ON "sessions"("user_id");

-- CreateIndex
CREATE INDEX "sessions_absolute_expiry_idx" ON "sessions"("absolute_expiry");

-- CreateIndex
CREATE UNIQUE INDEX "refresh_tokens_token_hash_key" ON "refresh_tokens"("token_hash");

-- CreateIndex
CREATE UNIQUE INDEX "refresh_tokens_replaced_by_key" ON "refresh_tokens"("replaced_by");

-- CreateIndex
CREATE INDEX "refresh_tokens_session_id_idx" ON "refresh_tokens"("session_id");

-- CreateIndex
CREATE INDEX "refresh_tokens_expires_at_idx" ON "refresh_tokens"("expires_at");

-- CreateIndex
CREATE INDEX "failed_login_attempts_email_attempted_at_idx" ON "failed_login_attempts"("email", "attempted_at");

-- CreateIndex
CREATE INDEX "failed_login_attempts_ip_hash_attempted_at_idx" ON "failed_login_attempts"("ip_hash", "attempted_at");

-- CreateIndex
CREATE UNIQUE INDEX "password_reset_tokens_token_hash_key" ON "password_reset_tokens"("token_hash");

-- CreateIndex
CREATE INDEX "password_reset_tokens_user_id_idx" ON "password_reset_tokens"("user_id");

-- CreateIndex
CREATE INDEX "password_reset_tokens_expires_at_idx" ON "password_reset_tokens"("expires_at");

-- CreateIndex
CREATE UNIQUE INDEX "dealers_code_key" ON "dealers"("code");

-- CreateIndex
CREATE INDEX "dealers_status_idx" ON "dealers"("status");

-- CreateIndex
CREATE INDEX "dealers_state_city_idx" ON "dealers"("state", "city");

-- CreateIndex
CREATE INDEX "dealers_public_listed_status_idx" ON "dealers"("public_listed", "status");

-- CreateIndex
CREATE INDEX "dealer_users_dealer_id_idx" ON "dealer_users"("dealer_id");

-- CreateIndex
CREATE UNIQUE INDEX "dealer_applications_created_dealer_id_key" ON "dealer_applications"("created_dealer_id");

-- CreateIndex
CREATE INDEX "dealer_applications_status_created_at_idx" ON "dealer_applications"("status", "created_at");

-- CreateIndex
CREATE UNIQUE INDEX "warehouses_code_key" ON "warehouses"("code");

-- CreateIndex
CREATE UNIQUE INDEX "products_slug_key" ON "products"("slug");

-- CreateIndex
CREATE UNIQUE INDEX "products_sku_prefix_key" ON "products"("sku_prefix");

-- CreateIndex
CREATE INDEX "products_status_is_featured_idx" ON "products"("status", "is_featured");

-- CreateIndex
CREATE INDEX "products_category_idx" ON "products"("category");

-- CreateIndex
CREATE UNIQUE INDEX "product_variants_sku_key" ON "product_variants"("sku");

-- CreateIndex
CREATE INDEX "product_variants_product_id_status_idx" ON "product_variants"("product_id", "status");

-- CreateIndex
CREATE UNIQUE INDEX "product_variants_product_id_size_label_key" ON "product_variants"("product_id", "size_label");

-- CreateIndex
CREATE UNIQUE INDEX "manufacturing_batches_batch_code_key" ON "manufacturing_batches"("batch_code");

-- CreateIndex
CREATE INDEX "manufacturing_batches_manufactured_on_idx" ON "manufacturing_batches"("manufactured_on");

-- CreateIndex
CREATE UNIQUE INDEX "mattresses_serial_number_key" ON "mattresses"("serial_number");

-- CreateIndex
CREATE UNIQUE INDEX "mattresses_qr_token_key" ON "mattresses"("qr_token");

-- CreateIndex
CREATE UNIQUE INDEX "mattresses_replacement_for_claim_id_key" ON "mattresses"("replacement_for_claim_id");

-- CreateIndex
CREATE INDEX "mattresses_current_dealer_id_current_status_idx" ON "mattresses"("current_dealer_id", "current_status");

-- CreateIndex
CREATE INDEX "mattresses_current_status_idx" ON "mattresses"("current_status");

-- CreateIndex
CREATE INDEX "mattresses_batch_id_idx" ON "mattresses"("batch_id");

-- CreateIndex
CREATE INDEX "mattresses_product_variant_id_idx" ON "mattresses"("product_variant_id");

-- CreateIndex
CREATE INDEX "mattresses_manufactured_at_idx" ON "mattresses"("manufactured_at");

-- CreateIndex
CREATE INDEX "mattress_events_mattress_id_occurred_at_idx" ON "mattress_events"("mattress_id", "occurred_at");

-- CreateIndex
CREATE INDEX "mattress_events_event_type_idx" ON "mattress_events"("event_type");

-- CreateIndex
CREATE UNIQUE INDEX "dispatches_dispatch_code_key" ON "dispatches"("dispatch_code");

-- CreateIndex
CREATE INDEX "dispatches_dealer_id_status_idx" ON "dispatches"("dealer_id", "status");

-- CreateIndex
CREATE INDEX "dispatches_status_dispatched_at_idx" ON "dispatches"("status", "dispatched_at");

-- CreateIndex
CREATE INDEX "dispatch_items_mattress_id_idx" ON "dispatch_items"("mattress_id");

-- CreateIndex
CREATE UNIQUE INDEX "dispatch_items_dispatch_id_mattress_id_key" ON "dispatch_items"("dispatch_id", "mattress_id");

-- CreateIndex
CREATE INDEX "dealer_receipts_dealer_id_received_at_idx" ON "dealer_receipts"("dealer_id", "received_at");

-- CreateIndex
CREATE INDEX "dealer_receipts_dispatch_id_idx" ON "dealer_receipts"("dispatch_id");

-- CreateIndex
CREATE INDEX "dealer_receipt_items_mattress_id_idx" ON "dealer_receipt_items"("mattress_id");

-- CreateIndex
CREATE UNIQUE INDEX "dealer_receipt_items_receipt_id_mattress_id_key" ON "dealer_receipt_items"("receipt_id", "mattress_id");

-- CreateIndex
CREATE INDEX "customers_dealer_id_idx" ON "customers"("dealer_id");

-- CreateIndex
CREATE INDEX "customers_phone_hash_idx" ON "customers"("phone_hash");

-- CreateIndex
CREATE UNIQUE INDEX "customers_dealer_id_phone_hash_key" ON "customers"("dealer_id", "phone_hash");

-- CreateIndex
CREATE UNIQUE INDEX "sales_mattress_id_key" ON "sales"("mattress_id");

-- CreateIndex
CREATE INDEX "sales_dealer_id_sold_at_idx" ON "sales"("dealer_id", "sold_at");

-- CreateIndex
CREATE INDEX "sales_customer_id_idx" ON "sales"("customer_id");

-- CreateIndex
CREATE UNIQUE INDEX "sales_dealer_id_invoice_number_key" ON "sales"("dealer_id", "invoice_number");

-- CreateIndex
CREATE UNIQUE INDEX "warranties_mattress_id_key" ON "warranties"("mattress_id");

-- CreateIndex
CREATE UNIQUE INDEX "warranties_sale_id_key" ON "warranties"("sale_id");

-- CreateIndex
CREATE INDEX "warranties_dealer_id_status_idx" ON "warranties"("dealer_id", "status");

-- CreateIndex
CREATE INDEX "warranties_end_date_idx" ON "warranties"("end_date");

-- CreateIndex
CREATE UNIQUE INDEX "warranty_claims_claim_number_key" ON "warranty_claims"("claim_number");

-- CreateIndex
CREATE INDEX "warranty_claims_dealer_id_status_idx" ON "warranty_claims"("dealer_id", "status");

-- CreateIndex
CREATE INDEX "warranty_claims_status_submitted_at_idx" ON "warranty_claims"("status", "submitted_at");

-- CreateIndex
CREATE INDEX "warranty_claims_mattress_id_idx" ON "warranty_claims"("mattress_id");

-- CreateIndex
CREATE INDEX "warranty_claims_risk_level_idx" ON "warranty_claims"("risk_level");

-- CreateIndex
CREATE UNIQUE INDEX "claim_media_storage_key_key" ON "claim_media"("storage_key");

-- CreateIndex
CREATE INDEX "claim_media_claim_id_idx" ON "claim_media"("claim_id");

-- CreateIndex
CREATE INDEX "claim_media_sha256_idx" ON "claim_media"("sha256");

-- CreateIndex
CREATE INDEX "claim_events_claim_id_created_at_idx" ON "claim_events"("claim_id", "created_at");

-- CreateIndex
CREATE UNIQUE INDEX "replacements_claim_id_key" ON "replacements"("claim_id");

-- CreateIndex
CREATE UNIQUE INDEX "replacements_original_mattress_id_key" ON "replacements"("original_mattress_id");

-- CreateIndex
CREATE UNIQUE INDEX "replacements_replacement_mattress_id_key" ON "replacements"("replacement_mattress_id");

-- CreateIndex
CREATE INDEX "replacements_dealer_id_idx" ON "replacements"("dealer_id");

-- CreateIndex
CREATE INDEX "notifications_user_id_read_at_idx" ON "notifications"("user_id", "read_at");

-- CreateIndex
CREATE INDEX "notifications_dealer_id_idx" ON "notifications"("dealer_id");

-- CreateIndex
CREATE INDEX "audit_logs_occurred_at_idx" ON "audit_logs"("occurred_at");

-- CreateIndex
CREATE INDEX "audit_logs_user_id_occurred_at_idx" ON "audit_logs"("user_id", "occurred_at");

-- CreateIndex
CREATE INDEX "audit_logs_entity_entity_id_idx" ON "audit_logs"("entity", "entity_id");

-- CreateIndex
CREATE INDEX "audit_logs_action_idx" ON "audit_logs"("action");

-- CreateIndex
CREATE INDEX "record_versions_entity_entity_id_idx" ON "record_versions"("entity", "entity_id");

-- CreateIndex
CREATE UNIQUE INDEX "record_versions_entity_entity_id_version_key" ON "record_versions"("entity", "entity_id", "version");

-- CreateIndex
CREATE UNIQUE INDEX "website_pages_slug_key" ON "website_pages"("slug");

-- CreateIndex
CREATE INDEX "website_pages_status_idx" ON "website_pages"("status");

-- CreateIndex
CREATE UNIQUE INDEX "website_products_product_id_key" ON "website_products"("product_id");

-- CreateIndex
CREATE INDEX "website_products_is_published_sort_order_idx" ON "website_products"("is_published", "sort_order");

-- CreateIndex
CREATE UNIQUE INDEX "seo_metadata_scope_entity_key_key" ON "seo_metadata"("scope", "entity_key");

-- CreateIndex
CREATE INDEX "faqs_is_published_category_sort_order_idx" ON "faqs"("is_published", "category", "sort_order");

-- CreateIndex
CREATE INDEX "contact_submissions_status_created_at_idx" ON "contact_submissions"("status", "created_at");

-- CreateIndex
CREATE INDEX "backup_runs_kind_started_at_idx" ON "backup_runs"("kind", "started_at");

-- AddForeignKey
ALTER TABLE "role_permissions" ADD CONSTRAINT "role_permissions_role_id_fkey" FOREIGN KEY ("role_id") REFERENCES "roles"("id") ON DELETE CASCADE ON UPDATE CASCADE;

-- AddForeignKey
ALTER TABLE "role_permissions" ADD CONSTRAINT "role_permissions_permission_id_fkey" FOREIGN KEY ("permission_id") REFERENCES "permissions"("id") ON DELETE CASCADE ON UPDATE CASCADE;

-- AddForeignKey
ALTER TABLE "user_roles" ADD CONSTRAINT "user_roles_user_id_fkey" FOREIGN KEY ("user_id") REFERENCES "users"("id") ON DELETE CASCADE ON UPDATE CASCADE;

-- AddForeignKey
ALTER TABLE "user_roles" ADD CONSTRAINT "user_roles_role_id_fkey" FOREIGN KEY ("role_id") REFERENCES "roles"("id") ON DELETE CASCADE ON UPDATE CASCADE;

-- AddForeignKey
ALTER TABLE "sessions" ADD CONSTRAINT "sessions_user_id_fkey" FOREIGN KEY ("user_id") REFERENCES "users"("id") ON DELETE CASCADE ON UPDATE CASCADE;

-- AddForeignKey
ALTER TABLE "refresh_tokens" ADD CONSTRAINT "refresh_tokens_session_id_fkey" FOREIGN KEY ("session_id") REFERENCES "sessions"("id") ON DELETE CASCADE ON UPDATE CASCADE;

-- AddForeignKey
ALTER TABLE "password_reset_tokens" ADD CONSTRAINT "password_reset_tokens_user_id_fkey" FOREIGN KEY ("user_id") REFERENCES "users"("id") ON DELETE CASCADE ON UPDATE CASCADE;

-- AddForeignKey
ALTER TABLE "dealer_users" ADD CONSTRAINT "dealer_users_user_id_fkey" FOREIGN KEY ("user_id") REFERENCES "users"("id") ON DELETE CASCADE ON UPDATE CASCADE;

-- AddForeignKey
ALTER TABLE "dealer_users" ADD CONSTRAINT "dealer_users_dealer_id_fkey" FOREIGN KEY ("dealer_id") REFERENCES "dealers"("id") ON DELETE RESTRICT ON UPDATE CASCADE;

-- AddForeignKey
ALTER TABLE "dealer_applications" ADD CONSTRAINT "dealer_applications_created_dealer_id_fkey" FOREIGN KEY ("created_dealer_id") REFERENCES "dealers"("id") ON DELETE SET NULL ON UPDATE CASCADE;

-- AddForeignKey
ALTER TABLE "product_variants" ADD CONSTRAINT "product_variants_product_id_fkey" FOREIGN KEY ("product_id") REFERENCES "products"("id") ON DELETE RESTRICT ON UPDATE CASCADE;

-- AddForeignKey
ALTER TABLE "manufacturing_batches" ADD CONSTRAINT "manufacturing_batches_warehouse_id_fkey" FOREIGN KEY ("warehouse_id") REFERENCES "warehouses"("id") ON DELETE RESTRICT ON UPDATE CASCADE;

-- AddForeignKey
ALTER TABLE "mattresses" ADD CONSTRAINT "mattresses_product_variant_id_fkey" FOREIGN KEY ("product_variant_id") REFERENCES "product_variants"("id") ON DELETE RESTRICT ON UPDATE CASCADE;

-- AddForeignKey
ALTER TABLE "mattresses" ADD CONSTRAINT "mattresses_batch_id_fkey" FOREIGN KEY ("batch_id") REFERENCES "manufacturing_batches"("id") ON DELETE RESTRICT ON UPDATE CASCADE;

-- AddForeignKey
ALTER TABLE "mattresses" ADD CONSTRAINT "mattresses_current_dealer_id_fkey" FOREIGN KEY ("current_dealer_id") REFERENCES "dealers"("id") ON DELETE RESTRICT ON UPDATE CASCADE;

-- AddForeignKey
ALTER TABLE "mattresses" ADD CONSTRAINT "mattresses_current_warehouse_id_fkey" FOREIGN KEY ("current_warehouse_id") REFERENCES "warehouses"("id") ON DELETE RESTRICT ON UPDATE CASCADE;

-- AddForeignKey
ALTER TABLE "mattress_events" ADD CONSTRAINT "mattress_events_mattress_id_fkey" FOREIGN KEY ("mattress_id") REFERENCES "mattresses"("id") ON DELETE CASCADE ON UPDATE CASCADE;

-- AddForeignKey
ALTER TABLE "mattress_events" ADD CONSTRAINT "mattress_events_actor_user_id_fkey" FOREIGN KEY ("actor_user_id") REFERENCES "users"("id") ON DELETE SET NULL ON UPDATE CASCADE;

-- AddForeignKey
ALTER TABLE "dispatches" ADD CONSTRAINT "dispatches_warehouse_id_fkey" FOREIGN KEY ("warehouse_id") REFERENCES "warehouses"("id") ON DELETE RESTRICT ON UPDATE CASCADE;

-- AddForeignKey
ALTER TABLE "dispatches" ADD CONSTRAINT "dispatches_dealer_id_fkey" FOREIGN KEY ("dealer_id") REFERENCES "dealers"("id") ON DELETE RESTRICT ON UPDATE CASCADE;

-- AddForeignKey
ALTER TABLE "dispatches" ADD CONSTRAINT "dispatches_created_by_fkey" FOREIGN KEY ("created_by") REFERENCES "users"("id") ON DELETE SET NULL ON UPDATE CASCADE;

-- AddForeignKey
ALTER TABLE "dispatch_items" ADD CONSTRAINT "dispatch_items_dispatch_id_fkey" FOREIGN KEY ("dispatch_id") REFERENCES "dispatches"("id") ON DELETE CASCADE ON UPDATE CASCADE;

-- AddForeignKey
ALTER TABLE "dispatch_items" ADD CONSTRAINT "dispatch_items_mattress_id_fkey" FOREIGN KEY ("mattress_id") REFERENCES "mattresses"("id") ON DELETE RESTRICT ON UPDATE CASCADE;

-- AddForeignKey
ALTER TABLE "dealer_receipts" ADD CONSTRAINT "dealer_receipts_dispatch_id_fkey" FOREIGN KEY ("dispatch_id") REFERENCES "dispatches"("id") ON DELETE RESTRICT ON UPDATE CASCADE;

-- AddForeignKey
ALTER TABLE "dealer_receipts" ADD CONSTRAINT "dealer_receipts_dealer_id_fkey" FOREIGN KEY ("dealer_id") REFERENCES "dealers"("id") ON DELETE RESTRICT ON UPDATE CASCADE;

-- AddForeignKey
ALTER TABLE "dealer_receipts" ADD CONSTRAINT "dealer_receipts_received_by_user_id_fkey" FOREIGN KEY ("received_by_user_id") REFERENCES "users"("id") ON DELETE RESTRICT ON UPDATE CASCADE;

-- AddForeignKey
ALTER TABLE "dealer_receipt_items" ADD CONSTRAINT "dealer_receipt_items_receipt_id_fkey" FOREIGN KEY ("receipt_id") REFERENCES "dealer_receipts"("id") ON DELETE CASCADE ON UPDATE CASCADE;

-- AddForeignKey
ALTER TABLE "dealer_receipt_items" ADD CONSTRAINT "dealer_receipt_items_mattress_id_fkey" FOREIGN KEY ("mattress_id") REFERENCES "mattresses"("id") ON DELETE RESTRICT ON UPDATE CASCADE;

-- AddForeignKey
ALTER TABLE "customers" ADD CONSTRAINT "customers_dealer_id_fkey" FOREIGN KEY ("dealer_id") REFERENCES "dealers"("id") ON DELETE RESTRICT ON UPDATE CASCADE;

-- AddForeignKey
ALTER TABLE "sales" ADD CONSTRAINT "sales_dealer_id_fkey" FOREIGN KEY ("dealer_id") REFERENCES "dealers"("id") ON DELETE RESTRICT ON UPDATE CASCADE;

-- AddForeignKey
ALTER TABLE "sales" ADD CONSTRAINT "sales_mattress_id_fkey" FOREIGN KEY ("mattress_id") REFERENCES "mattresses"("id") ON DELETE RESTRICT ON UPDATE CASCADE;

-- AddForeignKey
ALTER TABLE "sales" ADD CONSTRAINT "sales_customer_id_fkey" FOREIGN KEY ("customer_id") REFERENCES "customers"("id") ON DELETE RESTRICT ON UPDATE CASCADE;

-- AddForeignKey
ALTER TABLE "sales" ADD CONSTRAINT "sales_sold_by_user_id_fkey" FOREIGN KEY ("sold_by_user_id") REFERENCES "users"("id") ON DELETE RESTRICT ON UPDATE CASCADE;

-- AddForeignKey
ALTER TABLE "warranties" ADD CONSTRAINT "warranties_mattress_id_fkey" FOREIGN KEY ("mattress_id") REFERENCES "mattresses"("id") ON DELETE RESTRICT ON UPDATE CASCADE;

-- AddForeignKey
ALTER TABLE "warranties" ADD CONSTRAINT "warranties_sale_id_fkey" FOREIGN KEY ("sale_id") REFERENCES "sales"("id") ON DELETE RESTRICT ON UPDATE CASCADE;

-- AddForeignKey
ALTER TABLE "warranties" ADD CONSTRAINT "warranties_dealer_id_fkey" FOREIGN KEY ("dealer_id") REFERENCES "dealers"("id") ON DELETE RESTRICT ON UPDATE CASCADE;

-- AddForeignKey
ALTER TABLE "warranties" ADD CONSTRAINT "warranties_customer_id_fkey" FOREIGN KEY ("customer_id") REFERENCES "customers"("id") ON DELETE RESTRICT ON UPDATE CASCADE;

-- AddForeignKey
ALTER TABLE "warranty_claims" ADD CONSTRAINT "warranty_claims_mattress_id_fkey" FOREIGN KEY ("mattress_id") REFERENCES "mattresses"("id") ON DELETE RESTRICT ON UPDATE CASCADE;

-- AddForeignKey
ALTER TABLE "warranty_claims" ADD CONSTRAINT "warranty_claims_warranty_id_fkey" FOREIGN KEY ("warranty_id") REFERENCES "warranties"("id") ON DELETE RESTRICT ON UPDATE CASCADE;

-- AddForeignKey
ALTER TABLE "warranty_claims" ADD CONSTRAINT "warranty_claims_dealer_id_fkey" FOREIGN KEY ("dealer_id") REFERENCES "dealers"("id") ON DELETE RESTRICT ON UPDATE CASCADE;

-- AddForeignKey
ALTER TABLE "warranty_claims" ADD CONSTRAINT "warranty_claims_customer_id_fkey" FOREIGN KEY ("customer_id") REFERENCES "customers"("id") ON DELETE RESTRICT ON UPDATE CASCADE;

-- AddForeignKey
ALTER TABLE "warranty_claims" ADD CONSTRAINT "warranty_claims_submitted_by_user_id_fkey" FOREIGN KEY ("submitted_by_user_id") REFERENCES "users"("id") ON DELETE RESTRICT ON UPDATE CASCADE;

-- AddForeignKey
ALTER TABLE "warranty_claims" ADD CONSTRAINT "warranty_claims_reviewed_by_user_id_fkey" FOREIGN KEY ("reviewed_by_user_id") REFERENCES "users"("id") ON DELETE SET NULL ON UPDATE CASCADE;

-- AddForeignKey
ALTER TABLE "claim_media" ADD CONSTRAINT "claim_media_claim_id_fkey" FOREIGN KEY ("claim_id") REFERENCES "warranty_claims"("id") ON DELETE CASCADE ON UPDATE CASCADE;

-- AddForeignKey
ALTER TABLE "claim_media" ADD CONSTRAINT "claim_media_uploaded_by_user_id_fkey" FOREIGN KEY ("uploaded_by_user_id") REFERENCES "users"("id") ON DELETE RESTRICT ON UPDATE CASCADE;

-- AddForeignKey
ALTER TABLE "claim_events" ADD CONSTRAINT "claim_events_claim_id_fkey" FOREIGN KEY ("claim_id") REFERENCES "warranty_claims"("id") ON DELETE CASCADE ON UPDATE CASCADE;

-- AddForeignKey
ALTER TABLE "claim_events" ADD CONSTRAINT "claim_events_actor_user_id_fkey" FOREIGN KEY ("actor_user_id") REFERENCES "users"("id") ON DELETE SET NULL ON UPDATE CASCADE;

-- AddForeignKey
ALTER TABLE "replacements" ADD CONSTRAINT "replacements_claim_id_fkey" FOREIGN KEY ("claim_id") REFERENCES "warranty_claims"("id") ON DELETE RESTRICT ON UPDATE CASCADE;

-- AddForeignKey
ALTER TABLE "replacements" ADD CONSTRAINT "replacements_original_mattress_id_fkey" FOREIGN KEY ("original_mattress_id") REFERENCES "mattresses"("id") ON DELETE RESTRICT ON UPDATE CASCADE;

-- AddForeignKey
ALTER TABLE "replacements" ADD CONSTRAINT "replacements_replacement_mattress_id_fkey" FOREIGN KEY ("replacement_mattress_id") REFERENCES "mattresses"("id") ON DELETE RESTRICT ON UPDATE CASCADE;

-- AddForeignKey
ALTER TABLE "replacements" ADD CONSTRAINT "replacements_approved_by_user_id_fkey" FOREIGN KEY ("approved_by_user_id") REFERENCES "users"("id") ON DELETE RESTRICT ON UPDATE CASCADE;

-- AddForeignKey
ALTER TABLE "replacements" ADD CONSTRAINT "replacements_dispatch_id_fkey" FOREIGN KEY ("dispatch_id") REFERENCES "dispatches"("id") ON DELETE SET NULL ON UPDATE CASCADE;

-- AddForeignKey
ALTER TABLE "notifications" ADD CONSTRAINT "notifications_user_id_fkey" FOREIGN KEY ("user_id") REFERENCES "users"("id") ON DELETE CASCADE ON UPDATE CASCADE;

-- AddForeignKey
ALTER TABLE "notifications" ADD CONSTRAINT "notifications_dealer_id_fkey" FOREIGN KEY ("dealer_id") REFERENCES "dealers"("id") ON DELETE CASCADE ON UPDATE CASCADE;

-- AddForeignKey
ALTER TABLE "website_products" ADD CONSTRAINT "website_products_product_id_fkey" FOREIGN KEY ("product_id") REFERENCES "products"("id") ON DELETE CASCADE ON UPDATE CASCADE;

-- AddForeignKey
ALTER TABLE "faqs" ADD CONSTRAINT "faqs_product_id_fkey" FOREIGN KEY ("product_id") REFERENCES "products"("id") ON DELETE CASCADE ON UPDATE CASCADE;

-- AddForeignKey
ALTER TABLE "faqs" ADD CONSTRAINT "faqs_page_id_fkey" FOREIGN KEY ("page_id") REFERENCES "website_pages"("id") ON DELETE CASCADE ON UPDATE CASCADE;

-- AddForeignKey
ALTER TABLE "backup_runs" ADD CONSTRAINT "backup_runs_requested_by_fkey" FOREIGN KEY ("requested_by") REFERENCES "users"("id") ON DELETE SET NULL ON UPDATE CASCADE;
