import type { Messages } from '../en/index.ts'
import brandHeader from './brandHeader.ts'
import quotas from './quotas.ts'
import workloadsTable from './workloadsTable.ts'
import deployModal from './deployModal.ts'
import consoleModal from './consoleModal.ts'
import auth from './auth.ts'
import themeLanguage from './themeLanguage.ts'
import confirmationDialogs from './confirmationDialogs.ts'
import navigationSidebar from './navigationSidebar.ts'
import navigationTabs from './navigationTabs.ts'
import profileSettings from './profileSettings.ts'
import networkingIpam from './networkingIpam.ts'
import ipPoolsManagement from './ipPoolsManagement.ts'
import computeNodesClustersManagement from './computeNodesClustersManagement.ts'
import connectionTestingVerification from './connectionTestingVerification.ts'
import usersQuotasManagement from './usersQuotasManagement.ts'
import registrationSettings from './registrationSettings.ts'
import quotaGroupsPlans from './quotaGroupsPlans.ts'
import adminOversightFiltering from './adminOversightFiltering.ts'
import snapshotsVmDetails from './snapshotsVmDetails.ts'
import auditActions from './auditActions.ts'
import auditUiLabels from './auditUiLabels.ts'
import hostNodeImageSelection from './hostNodeImageSelection.ts'
import instanceMetrics from './instanceMetrics.ts'
import vpsPlans from './vpsPlans.ts'

// missing or extra key vs en = build error
const messages: Messages = {
  ...brandHeader,
  ...quotas,
  ...workloadsTable,
  ...deployModal,
  ...consoleModal,
  ...auth,
  ...themeLanguage,
  ...confirmationDialogs,
  ...navigationSidebar,
  ...navigationTabs,
  ...profileSettings,
  ...networkingIpam,
  ...ipPoolsManagement,
  ...computeNodesClustersManagement,
  ...connectionTestingVerification,
  ...usersQuotasManagement,
  ...registrationSettings,
  ...quotaGroupsPlans,
  ...adminOversightFiltering,
  ...snapshotsVmDetails,
  ...auditActions,
  ...auditUiLabels,
  ...hostNodeImageSelection,
  ...instanceMetrics,
  ...vpsPlans,
}

export default messages
