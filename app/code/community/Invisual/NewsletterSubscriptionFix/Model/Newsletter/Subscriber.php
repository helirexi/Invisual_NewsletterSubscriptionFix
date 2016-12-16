<?php
/**
 * @category  Invisual
 * @package   Invisual_NewsletterSubscriptionFix
 * @author    Pierre Arlt <info@invisual.de>
 */
/**
 * Class Invisual_NewsletterSubscriptionFix_Model_Newsletter_Subscriber
 */
class Invisual_NewsletterSubscriptionFix_Model_Newsletter_Subscriber extends Mage_Newsletter_Model_Subscriber
{
    /**
     * Load subscriber info by customer
     *
     * @param Mage_Customer_Model_Customer $customer
     * @return Mage_Newsletter_Model_Subscriber
     */
    public function loadByCustomer(Mage_Customer_Model_Customer $customer)
    {
        $data = $this->getResource()->loadByCustomer($customer);
        $this->addData($data);
        if (!empty($data) && $customer->getId() && !$this->getCustomerId()) {
            $this->setCustomerId($customer->getId());
            // create only new code if subscriber_confirm_code is empty,
            // without this every loadByCustomer call creates new code
            if (empty($this->getSubscriberConfirmCode())) {
                $this->setSubscriberConfirmCode($this->randomSequence());
            }

//          don't modify status data if we load a subscriber model
//
//          if ($this->getStatus()==self::STATUS_NOT_ACTIVE) {
//                   $this->setStatus($customer->getIsSubscribed() ? self::STATUS_SUBSCRIBED : self::STATUS_UNSUBSCRIBED);
//            }
            $this->save();
        }
        return $this;
    }


    /**
     * Subscribes by email, function is called on guest checkout
     *
     * @param string $email
     * @throws Exception
     * @return int
     */
    public function subscribe($email)
    {
        $this->loadByEmail($email);
        $customerSession = Mage::getSingleton('customer/session');
        $status = $this->getStatus();

        // modify data only if it is not saved in database or we have an empty subscriber_confirm_code field
        if (!$this->getId() || empty($this->getSubscriberConfirmCode())) {
            $this->setSubscriberConfirmCode($this->randomSequence());
        }

        // is newsletter confirmation needed?
        $isConfirmNeed = (Mage::getStoreConfig(self::XML_PATH_CONFIRMATION_FLAG) == 1) ? true : false;
        $isOwnSubscribes = false;

        $ownerId = Mage::getModel('customer/customer')
            ->setWebsiteId(Mage::app()->getStore()->getWebsiteId())
            ->loadByEmail($email)
            ->getId();

        /** @var boolean $isSubscribeOwnEmail */
        $isSubscribeOwnEmail = $customerSession->isLoggedIn() && $ownerId == $customerSession->getId();

        // set inital state onbly if object doesn't exits in database or we have a unsubscribed or not active state
        if (!$this->getId() || ($status == self::STATUS_UNSUBSCRIBED || $status == self::STATUS_NOT_ACTIVE)) {
            // if newsletter confirm is needed, initial state is unconfirmed
            if ($isConfirmNeed === true) {
                $this->setStatus(self::STATUS_UNCONFIRMED);
            } else {
                $this->setStatus(self::STATUS_SUBSCRIBED);
            }
            $this->setSubscriberEmail($email);
        }

        if ($isSubscribeOwnEmail) {
            $this->setStoreId($customerSession->getCustomer()->getStoreId());
            $this->setCustomerId($customerSession->getCustomerId());
        } else {
            $this->setStoreId(Mage::app()->getStore()->getId());
            $this->setCustomerId(0);
        }

        $this->setIsStatusChanged(true);

        try {
            $this->save();
            // send confirmation request only if confirmation is needed an initial state is unsubscribed
            if ($isConfirmNeed === true
                && $isOwnSubscribes === false
                && $this->getStatus() === self::STATUS_UNCONFIRMED
            ) {
                $this->sendConfirmationRequestEmail();
            } else if ($this->getIsStatusChanged() && $this->getStatus() == self::STATUS_UNSUBSCRIBED) {
                $this->sendUnsubscriptionEmail();
            } elseif ($this->getIsStatusChanged() && $this->getStatus() == self::STATUS_SUBSCRIBED) {
                $this->sendConfirmationSuccessEmail();
            } else {
                $this->sendConfirmationSuccessEmail();
            }

            return $this->getStatus();
        } catch (Exception $e) {
            throw new Exception($e->getMessage());
        }

    }


    /**
     * Saving customer subscription status, function is called on every customer save
     * 3x by checkout with account
     * 1x by create account
     * 1x by manage newsletter in customer dashboard
     *
     * @see Mage_Newsletter_Model_Observer "function subscribeCustomer($observer)"
     * @see app\code\core\Mage\Newsletter\etc\config.xml "customer_save_after event"
     *
     * @param   Mage_Customer_Model_Customer $customer
     * @return  Mage_Newsletter_Model_Subscriber
     */
    public function subscribeCustomer($customer)
    {
        $this->loadByCustomer($customer);

        if ($customer->getImportMode()) {
            $this->setImportMode(true);
        }

        if (!$customer->getIsSubscribed() && !$this->getId()) {
            // If subscription flag not set or customer is not a subscriber
            // and no subscribe below
            return $this;
        }
        if ($this->getStatus() == self::STATUS_UNCONFIRMED && !empty($this->getSubscriberConfirmCode())) {
            return $this;
        }

        // modify data only if it is not saved in database or we have an empty subscriber_confirm_code field
        if (!$this->getId() || empty($this->getSubscriberConfirmCode())) {
            $this->setSubscriberConfirmCode($this->randomSequence());
        }

        /*
         * Logical mismatch between customer registration confirmation code and customer password confirmation
         */
        $confirmation = null;
        if ($customer->isConfirmationRequired() && ($customer->getConfirmation() != $customer->getPassword())) {
            $confirmation = $customer->getConfirmation();
        }
        // is newsletter confirmation needed?
        $isConfirmNeed = (Mage::getStoreConfig(self::XML_PATH_CONFIRMATION_FLAG) == 1) ? true : false;
        $sendInformationEmail = false;
        if ($customer->hasIsSubscribed()) {
            $status = $customer->getIsSubscribed()
                ? ($isConfirmNeed
                    ? self::STATUS_UNCONFIRMED
                    : self::STATUS_SUBSCRIBED)
                : self::STATUS_UNSUBSCRIBED;
            /**
             * If subscription status has been changed then send email to the customer
             */
            if ($status != self::STATUS_UNCONFIRMED && $status != $this->getStatus()) {
                $sendInformationEmail = true;
            }
        } elseif (($this->getStatus() == self::STATUS_UNCONFIRMED) && (is_null($confirmation))) {
            $status = self::STATUS_SUBSCRIBED;
            $sendInformationEmail = true;
        } else {
            $status = ($this->getStatus() == self::STATUS_NOT_ACTIVE ? self::STATUS_UNSUBSCRIBED : $this->getStatus());
        }

        if ($status != $this->getStatus()) {
            $this->setIsStatusChanged(true);
        }

        $this->setStatus($status);

        if (!$this->getId()) {
            $storeId = $customer->getStoreId();
            if ($customer->getStoreId() == 0) {
                $storeId = Mage::app()->getWebsite($customer->getWebsiteId())->getDefaultStore()->getId();
            }
            $this->setStoreId($storeId)
                ->setCustomerId($customer->getId())
                ->setEmail($customer->getEmail());
        } else {
            $this->setStoreId($customer->getStoreId())
                ->setEmail($customer->getEmail());
        }

        $this->save();
        $sendSubscription = $customer->getData('sendSubscription') || $sendInformationEmail;
        // send confirmation request only if confirmation is needed an initial state is unsubscribed
        if ($status == self::STATUS_UNCONFIRMED && $isConfirmNeed) {
            $this->sendConfirmationRequestEmail();
        }
        if (is_null($sendSubscription) xor $sendSubscription) {
            if ($this->getIsStatusChanged() && $status == self::STATUS_UNSUBSCRIBED) {
                $this->sendUnsubscriptionEmail();
            } elseif ($this->getIsStatusChanged() && $status == self::STATUS_SUBSCRIBED) {
                $this->sendConfirmationSuccessEmail();
            }
        }

        return $this;
    }

    /**
     * Confirms subscriber newsletter.
     * function is called by newsletter confirmation action
     * reset confirmation code to null if code from getParam == code from database
     *
     * @param string $code
     * @return boolean
     */
    public function confirm($code)
    {
        if ($this->getCode() == $code) {
            $this->setStatus(self::STATUS_SUBSCRIBED)
                ->setIsStatusChanged(true)
                ->setSubscriberConfirmCode(null)
                ->save();
            return true;
        }

        return false;
    }
}